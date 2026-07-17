<?php

namespace App\Http\Controllers;

use App\Http\Requests\AiHelper\ListAiHelperAdminKnowledgeRequest;
use App\Http\Requests\AiHelper\ListAiHelperReportsRequest;
use App\Http\Requests\AiHelper\ReportAiHelperMessageRequest;
use App\Http\Requests\AiHelper\StreamAiHelperMessageRequest;
use App\Http\Requests\AiHelper\UpdateAiHelperAdminKnowledgeRequest;
use App\Http\Requests\AiHelper\UpdateAiHelperReportRequest;
use App\Http\Requests\AiHelper\UploadAiHelperDocumentRequest;
use App\Http\Requests\AiHelper\UploadAiHelperMarkdownKnowledgeRequest;
use App\Models\AiHelperDocument;
use App\Models\AiHelperKnowledgeChunk;
use App\Models\AiHelperKnowledgeEntry;
use App\Models\AiHelperMessage;
use App\Models\AiHelperResponseReport;
use App\Models\AiHelperThread;
use App\Models\User;
use App\Services\AiHelperApiResponder;
use App\Services\AiHelperAuthorizationService;
use App\Services\AiHelperConversationService;
use App\Services\AiHelperDocumentQuotaService;
use App\Services\AiHelperKnowledgeLifecycleService;
use App\Services\AiHelperKnowledgeProcessingService;
use App\Services\AiHelperKnowledgeQuotaService;
use App\Services\AiHelperKnowledgeService;
use App\Services\AiHelperMarkdownKnowledgeParser;
use App\Services\AiHelperOpenAiService;
use App\Services\AiHelperReliabilityMetrics;
use App\Services\AiHelperResponsePipeline;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AiHelperController extends Controller
{
    private const CONVERSATION_PURPOSE_CHAT = 'chat';

    private const CONVERSATION_PURPOSE_EMBEDDED_HELPER = 'embedded_helper';

    public function __construct(
        private readonly AiHelperKnowledgeService $knowledge,
        private readonly AiHelperOpenAiService $openAi,
        private readonly AiHelperAuthorizationService $authorization,
        private readonly AiHelperKnowledgeProcessingService $knowledgeProcessor,
        private readonly AiHelperMarkdownKnowledgeParser $markdownParser,
        private readonly AiHelperDocumentQuotaService $documentQuota,
        private readonly AiHelperKnowledgeQuotaService $knowledgeQuota,
        private readonly AiHelperKnowledgeLifecycleService $knowledgeLifecycle,
        private readonly AiHelperConversationService $conversation,
        private readonly AiHelperResponsePipeline $responsePipeline,
        private readonly AiHelperReliabilityMetrics $reliabilityMetrics,
        private readonly AiHelperApiResponder $responder,
    ) {}

    public function context(Request $request): JsonResponse
    {
        try {
            $payload = $request->only(['path', 'route_path', 'route_name', 'title', 'search', 'params']);
            $context = $this->knowledge->buildContext($payload, $request->user());

            return response()->json(['data' => [
                'page' => $context['page'],
                'available' => (bool) ($context['available'] ?? false),
                'corpus' => $context['corpus'] ?? ['ready' => true, 'counts' => []],
            ]]);
        } catch (Throwable $e) {
            return $this->safeFailure($request, $e, 'context');
        }
    }

    public function thread(Request $request): JsonResponse
    {
        try {
            $threadId = $request->integer('thread_id') ?: null;
            $threadQuery = AiHelperThread::query()->where('user_id', $request->user()->id);
            $thread = $threadId
                ? $this->regularThreadById($threadQuery, $threadId)
                : $this->latestRegularThreadForUser($request->user()->id);

            return response()->json([
                'data' => [
                    'thread' => $thread ? $this->formatThread($thread) : null,
                    'messages' => $thread
                        ? $thread->messages()
                            ->whereIn('role', [AiHelperMessage::ROLE_USER, AiHelperMessage::ROLE_ASSISTANT])
                            ->where('status', '!=', AiHelperMessage::STATUS_FAILED)
                            ->orderBy('created_at')
                            ->limit(80)
                            ->get()
                            ->map(fn (AiHelperMessage $message) => $this->formatMessage($message))
                            ->values()
                        : [],
                ],
            ]);
        } catch (Throwable $e) {
            return $this->safeFailure($request, $e, 'thread');
        }
    }

    public function threads(Request $request): JsonResponse
    {
        try {
            $threads = AiHelperThread::query()
                ->where('user_id', $request->user()->id)
                ->where(function ($query) {
                    $query->whereNull('conversation_purpose')
                        ->orWhere('conversation_purpose', 'chat');
                })
                ->with(['messages' => function ($query) {
                    $query->whereIn('role', [AiHelperMessage::ROLE_USER, AiHelperMessage::ROLE_ASSISTANT])
                        ->where('status', '!=', AiHelperMessage::STATUS_FAILED)
                        ->latest('created_at')
                        ->limit(1);
                }])
                ->latest('updated_at')
                ->limit(100)
                ->get()
                ->filter(fn (AiHelperThread $thread) => $this->isRegularChatThread($thread))
                ->take(30)
                ->map(function (AiHelperThread $thread) {
                    $lastMessage = $thread->messages->first();

                    return [
                        ...$this->formatThread($thread),
                        'last_message' => $lastMessage ? Str::limit((string) $lastMessage->content, 90, '') : '',
                    ];
                })
                ->values();

            return response()->json(['data' => $threads]);
        } catch (Throwable $e) {
            return $this->safeFailure($request, $e, 'threads');
        }
    }

    public function destroyThread(Request $request, int $threadId): JsonResponse
    {
        try {
            $thread = AiHelperThread::query()
                ->where('user_id', $request->user()->id)
                ->where('id', $threadId)
                ->first();

            if (! $thread) {
                return response()->json([
                    'message' => 'Chat not found.',
                    'code' => 'AI_HELPER_THREAD_NOT_FOUND',
                ], 404);
            }

            $thread->delete();

            return response()->json(['message' => 'Chat deleted.']);
        } catch (Throwable $e) {
            return $this->safeFailure($request, $e, 'destroy_thread');
        }
    }

    public function documents(Request $request): JsonResponse
    {
        try {
            $documents = $this->visibleDocumentsQuery($request->user())
                ->with('uploader:id,name,email')
                ->orderByRaw('CASE WHEN uploaded_by = ? THEN 0 ELSE 1 END', [$request->user()->id])
                ->latest('created_at')
                ->limit(max(1, (int) config('ai_helper.knowledge_catalogue_limit', 250)))
                ->get()
                ->map(fn (AiHelperDocument $document) => $this->formatDocument($document))
                ->values();

            return response()->json(['data' => $documents]);
        } catch (Throwable $e) {
            return $this->safeFailure($request, $e, 'documents');
        }
    }

    public function documentDetail(Request $request, int $documentId): JsonResponse
    {
        try {
            $document = $this->resolveVisibleDocument($request->user(), $documentId);
            if (! $document) {
                return response()->json([
                    'message' => 'Reference document not found.',
                    'code' => 'AI_HELPER_DOCUMENT_NOT_FOUND',
                ], 404);
            }

            return response()->json([
                'data' => $this->formatDocument($document->loadMissing('uploader:id,name,email')),
            ]);
        } catch (Throwable $e) {
            return $this->safeFailure($request, $e, 'document_detail');
        }
    }

    public function documentFile(Request $request, int $documentId)
    {
        try {
            $document = $this->resolveVisibleDocument($request->user(), $documentId);
            $sourcePath = trim((string) ($document?->source_path ?? ''));

            if (! $document || $sourcePath === '' || ! Storage::disk('local')->exists($sourcePath)) {
                return response()->json([
                    'message' => 'Reference document file not found.',
                    'code' => 'AI_HELPER_DOCUMENT_FILE_NOT_FOUND',
                ], 404);
            }

            $filename = basename(str_replace('\\', '/', (string) ($document->source_filename ?: 'document.pdf')));
            $fallbackFilename = preg_replace('/[^\x20-\x7E]|%/', '-', Str::ascii($filename)) ?: 'document.pdf';
            $response = response()->file(
                Storage::disk('local')->path($sourcePath),
                ['Content-Type' => 'application/pdf'],
            );

            return $response->setContentDisposition(
                ResponseHeaderBag::DISPOSITION_INLINE,
                $filename,
                $fallbackFilename,
            );
        } catch (Throwable $e) {
            return $this->safeFailure($request, $e, 'document_file');
        }
    }

    public function uploadDocument(UploadAiHelperDocumentRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $storedPath = null;

        try {
            $actor = $request->user();
            $file = $request->file('file');
            $quota = $this->documentQuota->checkUpload($actor, $file);
            if (! ($quota['ok'] ?? false)) {
                return $this->responder->error(
                    $request,
                    $quota['message'] ?? 'Reference document upload limit reached.',
                    $quota['code'] ?? 'AI_HELPER_DOCUMENT_UPLOAD_LIMIT',
                    422,
                );
            }

            $handle = fopen($file->getPathname(), 'rb');
            $signature = $handle !== false ? fread($handle, 5) : false;
            if (is_resource($handle)) {
                fclose($handle);
            }
            if ($signature !== '%PDF-') {
                return response()->json([
                    'message' => 'Upload a valid PDF document.',
                    'code' => 'AI_HELPER_DOCUMENT_INVALID_PDF',
                ], 422);
            }

            $sourceFilename = $file->getClientOriginalName() ?: 'document.pdf';
            $storedPath = $file->store("ai-helper/documents/{$actor->id}", 'local');
            if (! is_string($storedPath) || $storedPath === '') {
                throw new RuntimeException('The reference document could not be stored.');
            }

            $title = trim((string) ($validated['title'] ?? ''));
            if ($title === '') {
                $title = pathinfo($sourceFilename, PATHINFO_FILENAME) ?: 'Reference document';
            }

            $document = AiHelperDocument::create([
                'uploaded_by' => $actor->id,
                'title' => Str::limit($title, 140, ''),
                'source_filename' => Str::limit($sourceFilename, 255, ''),
                'source_mime' => 'application/pdf',
                'source_size' => $file->getSize(),
                'source_path' => $storedPath,
                'source_hash' => hash_file('sha256', Storage::disk('local')->path($storedPath)) ?: null,
                'visibility' => (string) ($validated['visibility'] ?? AiHelperDocument::VISIBILITY_PERSONAL),
                'acknowledged_at' => now(),
            ]);

            AuditLogger::log($request, 'ai_helper_document_uploaded', $actor, [
                'document_id' => $document->id,
                'visibility' => $document->visibility,
                'source_filename' => $document->source_filename,
                'source_size' => $document->source_size,
                'request_id' => $this->responder->requestId($request),
            ]);

            return response()->json([
                'message' => 'PDF added to the reference document library. It is available for viewing only and was not ingested by Ask AI.',
                'data' => $this->formatDocument($document->loadMissing('uploader:id,name,email')),
                'request_id' => $this->responder->requestId($request),
            ], 201);
        } catch (Throwable $e) {
            if (is_string($storedPath) && $storedPath !== '') {
                Storage::disk('local')->delete($storedPath);
            }

            return $this->safeFailure($request, $e, 'document_upload');
        }
    }

    public function destroyDocument(Request $request, int $documentId): JsonResponse
    {
        try {
            $document = AiHelperDocument::query()->find($documentId);
            if (! $document || ! $this->authorization->canManageDocument($request->user(), $document)) {
                return response()->json([
                    'message' => 'Reference document not found.',
                    'code' => 'AI_HELPER_DOCUMENT_NOT_FOUND',
                ], 404);
            }

            $sourcePath = trim((string) ($document->source_path ?? ''));
            if ($sourcePath !== '') {
                Storage::disk('local')->delete($sourcePath);
            }
            $document->forceFill(['source_path' => null])->save();
            $document->delete();

            AuditLogger::log($request, 'ai_helper_document_deleted', $request->user(), [
                'document_id' => $document->id,
                'request_id' => $this->responder->requestId($request),
            ]);

            return response()->json([
                'message' => 'Reference document deleted.',
                'request_id' => $this->responder->requestId($request),
            ]);
        } catch (Throwable $e) {
            return $this->safeFailure($request, $e, 'document_delete');
        }
    }

    public function uploadMarkdownKnowledge(UploadAiHelperMarkdownKnowledgeRequest $request): JsonResponse
    {
        if ($response = $this->authorizeSystemAdministrator($request)) {
            return $response;
        }

        $validated = $request->validated();

        try {
            $actor = $request->user();
            $file = $request->file('file');
            $quota = $this->knowledgeQuota->checkUpload($actor, $file);
            if (! ($quota['ok'] ?? false)) {
                return $this->responder->error(
                    $request,
                    $quota['message'] ?? 'Ask AI knowledge upload limit reached.',
                    $quota['code'] ?? 'AI_HELPER_KNOWLEDGE_UPLOAD_LIMIT',
                    422,
                );
            }

            $sourceFilename = $file->getClientOriginalName() ?: 'knowledge.md';
            $sourceExtension = Str::lower((string) $file->getClientOriginalExtension());
            if (! in_array($sourceExtension, ['md', 'markdown'], true)) {
                return response()->json([
                    'message' => 'Upload a Markdown .md file.',
                    'code' => 'AI_HELPER_MARKDOWN_INVALID_FILE',
                ], 422);
            }
            $storedPath = $file->store("ai-helper/knowledge/markdown/{$actor->id}", 'local');
            $parsed = $this->markdownParser->parseFile(storage_path('app/'.$storedPath), false);
            $frontmatter = $parsed['frontmatter'];
            $content = $parsed['content'];

            $requestScopeType = trim((string) ($validated['scope_type'] ?? ''));
            $frontmatterScopeType = trim((string) ($frontmatter['scope_type'] ?? ''));
            $scopeType = $requestScopeType !== ''
                ? $requestScopeType
                : ($frontmatterScopeType !== '' ? $frontmatterScopeType : AiHelperKnowledgeEntry::SCOPE_GLOBAL);

            if (! in_array($scopeType, [
                AiHelperKnowledgeEntry::SCOPE_GLOBAL,
                AiHelperKnowledgeEntry::SCOPE_MODULE,
                AiHelperKnowledgeEntry::SCOPE_ROUTE,
            ], true)) {
                return response()->json([
                    'message' => 'Choose a valid scope for this Markdown knowledge source.',
                    'code' => 'AI_HELPER_KNOWLEDGE_INVALID_SCOPE',
                ], 422);
            }

            $moduleKey = trim((string) ($validated['module_key'] ?? ''));
            if ($moduleKey === '') {
                $moduleKey = trim((string) ($frontmatter['module_key'] ?? ''));
            }
            $routeKey = trim((string) ($frontmatter['route_key'] ?? ''));

            if ($scopeType === AiHelperKnowledgeEntry::SCOPE_GLOBAL) {
                $moduleKey = '';
                $routeKey = '';
            }

            if (
                $scopeType === AiHelperKnowledgeEntry::SCOPE_MODULE &&
                ! in_array($moduleKey, AiHelperKnowledgeEntry::USER_UPLOAD_MODULE_KEYS, true)
            ) {
                return response()->json([
                    'message' => 'Choose a valid module for this Markdown knowledge source.',
                    'code' => 'AI_HELPER_KNOWLEDGE_INVALID_MODULE',
                ], 422);
            }

            if ($scopeType === AiHelperKnowledgeEntry::SCOPE_ROUTE && $routeKey === '') {
                return response()->json([
                    'message' => 'Route-scoped Markdown knowledge requires route_key frontmatter.',
                    'code' => 'AI_HELPER_KNOWLEDGE_INVALID_ROUTE',
                ], 422);
            }

            $title = trim((string) ($validated['title'] ?? ''));
            if ($title === '') {
                $title = trim((string) ($frontmatter['title'] ?? ''));
            }
            if ($title === '') {
                $title = pathinfo($sourceFilename, PATHINFO_FILENAME) ?: 'Markdown knowledge';
            }

            $summary = trim((string) ($frontmatter['summary'] ?? '')) ?: null;
            $active = $this->markdownParser->booleanValue($frontmatter['active'] ?? true);
            $version = max(1, (int) ($frontmatter['version'] ?? 1));
            $tags = $this->markdownParser->tags($frontmatter['tags'] ?? null, [
                'uploaded',
                'markdown',
                $scopeType,
                $moduleKey,
                $routeKey,
            ]);

            $entry = AiHelperKnowledgeEntry::create([
                'uploaded_by' => $actor->id,
                'module_key' => $moduleKey !== '' ? $moduleKey : null,
                'route_key' => $routeKey !== '' ? $routeKey : null,
                'title' => Str::limit($title, 140, ''),
                'content' => $content,
                'summary' => $summary,
                'source_filename' => Str::limit($sourceFilename, 255, ''),
                'source_mime' => 'text/markdown',
                'source_size' => $file->getSize(),
                'source_path' => $storedPath,
                'content_hash' => hash('sha256', $content),
                'scope_type' => $scopeType,
                'visibility' => AiHelperKnowledgeEntry::VISIBILITY_SHARED,
                'status' => AiHelperKnowledgeEntry::STATUS_PROCESSING,
                'review_status' => AiHelperKnowledgeEntry::REVIEW_APPROVED,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'active' => false,
                'acknowledged_at' => now(),
                'processed_at' => null,
                'error' => null,
                'tags' => $tags,
                'version' => $version,
            ]);

            $ingestionRunId = $this->knowledgeLifecycle->beginIngestion($entry);
            $this->knowledgeProcessor->process($entry->id, $ingestionRunId);
            if (! $active && $entry->fresh()?->status === AiHelperKnowledgeEntry::STATUS_ACTIVE) {
                $entry->forceFill([
                    'status' => AiHelperKnowledgeEntry::STATUS_DISABLED,
                    'active' => false,
                ])->save();
            }

            AuditLogger::log($request, 'ai_helper_markdown_knowledge_uploaded', $actor, [
                'knowledge_entry_id' => $entry->id,
                'scope_type' => $entry->scope_type,
                'source_filename' => $entry->source_filename,
                'source_size' => $entry->source_size,
                'request_id' => $this->responder->requestId($request),
            ]);

            return response()->json([
                'message' => 'Markdown knowledge uploaded. Ask AI can use it now.',
                'data' => $this->formatKnowledgeEntry($entry->fresh(['uploader', 'reviewer'])),
                'request_id' => $this->responder->requestId($request),
            ], 201);
        } catch (RuntimeException $e) {
            return $this->responder->error($request, $e->getMessage(), 'AI_HELPER_MARKDOWN_INVALID', 422);
        } catch (Throwable $e) {
            return $this->safeFailure($request, $e, 'markdown_knowledge_upload');
        }
    }

    public function reportMessage(ReportAiHelperMessageRequest $request, int $messageId): JsonResponse
    {
        $validated = $request->validated();
        $reason = (string) $validated['reason'];

        try {
            $actor = $request->user();
            $message = AiHelperMessage::query()
                ->where('id', $messageId)
                ->where('role', AiHelperMessage::ROLE_ASSISTANT)
                ->where('status', AiHelperMessage::STATUS_COMPLETED)
                ->whereHas('thread', fn ($query) => $query->where('user_id', $actor->id))
                ->with('thread')
                ->first();

            if (! $message) {
                return response()->json([
                    'message' => 'Ask AI response not found.',
                    'code' => 'AI_HELPER_MESSAGE_NOT_FOUND',
                ], 404);
            }

            $precedingUserMessage = $message->thread->messages()
                ->where('role', AiHelperMessage::ROLE_USER)
                ->where('id', '<', $message->id)
                ->latest('id')
                ->first();

            $snapshotMessages = $message->thread->messages()
                ->whereIn('role', [AiHelperMessage::ROLE_USER, AiHelperMessage::ROLE_ASSISTANT])
                ->orderBy('created_at')
                ->limit(120)
                ->get()
                ->map(fn (AiHelperMessage $threadMessage) => $this->formatMessage($threadMessage))
                ->values()
                ->all();

            $report = AiHelperResponseReport::create([
                'reporter_user_id' => $actor->id,
                'thread_id' => $message->thread_id,
                'assistant_message_id' => $message->id,
                'preceding_user_message_id' => $precedingUserMessage?->id,
                'reason' => $reason,
                'status' => AiHelperResponseReport::STATUS_NEW,
                'assistant_content' => (string) $message->content,
                'preceding_user_content' => $precedingUserMessage ? (string) $precedingUserMessage->content : null,
                'page_context' => $message->route_context ?: $message->thread->latest_route_context ?: [],
                'chat_snapshot' => [
                    'thread' => $this->formatThread($message->thread),
                    'messages' => $snapshotMessages,
                ],
                'openai_response_id' => $message->openai_response_id,
                'reporter_ip' => Str::limit((string) $request->ip(), 64, ''),
                'reporter_user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
            ]);
            AuditLogger::log($request, 'ai_helper_response_report_created', $actor, [
                'report_id' => $report->id,
                'thread_id' => $message->thread_id,
                'assistant_message_id' => $message->id,
                'request_id' => $this->responder->requestId($request),
            ]);

            return response()->json([
                'message' => 'Ask AI response report submitted.',
                'data' => $this->formatReportSummary($report->fresh(['reporter', 'thread'])),
                'request_id' => $this->responder->requestId($request),
            ], 201);
        } catch (Throwable $e) {
            return $this->safeFailure($request, $e, 'report_message');
        }
    }

    public function reports(ListAiHelperReportsRequest $request): JsonResponse
    {
        if ($response = $this->authorizeSystemAdministrator($request)) {
            return $response;
        }

        $validated = $request->validated();

        try {
            $status = strtolower((string) ($validated['status'] ?? 'new'));
            $perPage = min(50, max(1, (int) ($validated['per_page'] ?? 20)));
            $query = AiHelperResponseReport::query()
                ->with(['reporter', 'thread', 'reviewer'])
                ->latest('created_at');

            if ($status === 'actionable') {
                $query->whereIn('status', [
                    AiHelperResponseReport::STATUS_NEW,
                    AiHelperResponseReport::STATUS_REVIEWING,
                ]);
            } elseif ($status !== '' && $status !== 'all' && in_array($status, AiHelperResponseReport::STATUSES, true)) {
                $query->where('status', $status);
            }

            $page = $query->paginate($perPage);
            $countsByStatus = AiHelperResponseReport::query()
                ->selectRaw('status, count(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status')
                ->all();

            return response()->json([
                'data' => collect($page->items())
                    ->map(fn (AiHelperResponseReport $report) => $this->formatReportSummary($report))
                    ->values(),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                    'counts' => [
                        'new' => (int) ($countsByStatus['new'] ?? 0),
                        'reviewing' => (int) ($countsByStatus['reviewing'] ?? 0),
                        'resolved' => (int) ($countsByStatus['resolved'] ?? 0),
                        'dismissed' => (int) ($countsByStatus['dismissed'] ?? 0),
                        'actionable' => (int) ($countsByStatus['new'] ?? 0)
                            + (int) ($countsByStatus['reviewing'] ?? 0),
                        'all' => array_sum(array_map('intval', $countsByStatus)),
                    ],
                ],
            ]);
        } catch (Throwable $e) {
            return $this->safeFailure($request, $e, 'reports');
        }
    }

    public function report(Request $request, int $reportId): JsonResponse
    {
        if ($response = $this->authorizeSystemAdministrator($request)) {
            return $response;
        }

        try {
            $report = AiHelperResponseReport::query()
                ->with(['reporter', 'thread', 'reviewer'])
                ->find($reportId);

            if (! $report) {
                return response()->json([
                    'message' => 'Ask AI response report not found.',
                    'code' => 'AI_HELPER_REPORT_NOT_FOUND',
                ], 404);
            }

            return response()->json(['data' => $this->formatReportDetail($report)]);
        } catch (Throwable $e) {
            return $this->safeFailure($request, $e, 'report_detail');
        }
    }

    public function updateReport(UpdateAiHelperReportRequest $request, int $reportId): JsonResponse
    {
        if ($response = $this->authorizeSystemAdministrator($request)) {
            return $response;
        }

        $validated = $request->validated();

        try {
            $report = AiHelperResponseReport::query()->find($reportId);
            if (! $report) {
                return response()->json([
                    'message' => 'Ask AI response report not found.',
                    'code' => 'AI_HELPER_REPORT_NOT_FOUND',
                ], 404);
            }

            $status = $validated['status'];

            $report->forceFill([
                'status' => $status,
                'admin_note' => trim((string) ($validated['admin_note'] ?? '')) ?: null,
                'reviewed_by' => $status === AiHelperResponseReport::STATUS_NEW ? null : $request->user()->id,
                'reviewed_at' => $status === AiHelperResponseReport::STATUS_NEW ? null : now(),
            ])->save();
            AuditLogger::log($request, 'ai_helper_response_report_updated', $request->user(), [
                'report_id' => $report->id,
                'status' => $status,
                'request_id' => $this->responder->requestId($request),
            ]);

            return response()->json([
                'message' => 'Ask AI response report updated.',
                'data' => $this->formatReportDetail($report->fresh(['reporter', 'thread', 'reviewer'])),
                'request_id' => $this->responder->requestId($request),
            ]);
        } catch (Throwable $e) {
            return $this->safeFailure($request, $e, 'report_update');
        }
    }

    public function adminKnowledge(ListAiHelperAdminKnowledgeRequest $request): JsonResponse
    {
        if ($response = $this->authorizeSystemAdministrator($request)) {
            return $response;
        }

        $validated = $request->validated();

        try {
            $status = strtolower((string) ($validated['status'] ?? 'pending'));
            $perPage = min(50, max(1, (int) ($validated['per_page'] ?? 20)));

            $query = AiHelperKnowledgeEntry::query()
                ->where('source_mime', 'text/markdown')
                ->with(['uploader:id,name,email', 'reviewer:id,name,email'])
                ->withCount('chunks')
                ->latest('created_at');

            if (($validated['module_key'] ?? '') !== '') {
                $query->where('module_key', $validated['module_key']);
            }

            if (in_array($status, AiHelperKnowledgeEntry::REVIEW_STATUSES, true)) {
                $query->where('review_status', $status);
            } elseif (in_array($status, AiHelperKnowledgeEntry::STATUSES, true)) {
                $query->where('status', $status);
            }

            $page = $query->paginate($perPage);
            $reviewCounts = AiHelperKnowledgeEntry::query()
                ->where('source_mime', 'text/markdown')
                ->selectRaw('review_status, count(*) as aggregate')
                ->groupBy('review_status')
                ->pluck('aggregate', 'review_status')
                ->all();

            return response()->json([
                'data' => collect($page->items())
                    ->map(fn (AiHelperKnowledgeEntry $entry) => $this->formatKnowledgeEntry($entry))
                    ->values(),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                    'counts' => [
                        'pending' => (int) ($reviewCounts[AiHelperKnowledgeEntry::REVIEW_PENDING] ?? 0),
                        'approved' => (int) ($reviewCounts[AiHelperKnowledgeEntry::REVIEW_APPROVED] ?? 0),
                        'rejected' => (int) ($reviewCounts[AiHelperKnowledgeEntry::REVIEW_REJECTED] ?? 0),
                        'all' => array_sum(array_map('intval', $reviewCounts)),
                    ],
                ],
            ]);
        } catch (Throwable $e) {
            return $this->safeFailure($request, $e, 'admin_knowledge');
        }
    }

    public function diagnostics(Request $request): JsonResponse
    {
        if ($response = $this->authorizeSystemAdministrator($request)) {
            return $response;
        }

        try {
            $knowledgeStorageBytes = (int) AiHelperKnowledgeEntry::query()
                ->withTrashed()
                ->where('source_mime', 'text/markdown')
                ->sum('source_size');
            $failedUploads = AiHelperKnowledgeEntry::query()
                ->where('source_mime', 'text/markdown')
                ->where('status', AiHelperKnowledgeEntry::STATUS_FAILED)
                ->latest('updated_at')
                ->limit(10)
                ->get(['id', 'title', 'knowledge_type', 'source_filename', 'source_path', 'error', 'updated_at'])
                ->map(fn (AiHelperKnowledgeEntry $entry) => [
                    'id' => $entry->id,
                    'title' => $entry->title,
                    'knowledge_type' => $entry->knowledge_type,
                    'guide_key' => $entry->knowledge_type === AiHelperKnowledgeEntry::KNOWLEDGE_SYSTEM_GUIDE
                        ? Str::after((string) $entry->source_path, 'seed:system-guide:')
                        : null,
                    'source_filename' => $entry->knowledge_type === AiHelperKnowledgeEntry::KNOWLEDGE_SYSTEM_GUIDE
                        ? null
                        : $entry->source_filename,
                    'error' => $entry->error,
                    'updated_at' => optional($entry->updated_at)->toIso8601String(),
                ])
                ->values();
            $retrievalSchemaReady = Schema::hasColumn('ai_helper_knowledge_entries', 'embedding_status')
                && Schema::hasColumn('ai_helper_knowledge_chunks', 'embedding');
            $usableChunkQuery = AiHelperKnowledgeChunk::query()
                ->where('active', true)
                ->whereHas('knowledgeEntry', fn ($query) => $query
                    ->where('source_mime', 'text/markdown')
                    ->where('active', true)
                    ->where('review_status', AiHelperKnowledgeEntry::REVIEW_APPROVED)
                    ->whereIn('status', [AiHelperKnowledgeEntry::STATUS_ACTIVE, AiHelperKnowledgeEntry::STATUS_PROCESSING]));
            $activeChunks = (clone $usableChunkQuery)->count();
            $embeddedChunks = $retrievalSchemaReady
                ? (clone $usableChunkQuery)->whereNotNull('embedding')->count()
                : 0;
            $markdownSources = AiHelperKnowledgeEntry::query()->where('source_mime', 'text/markdown')->count();
            $semanticSources = $retrievalSchemaReady
                ? AiHelperKnowledgeEntry::query()->where('source_mime', 'text/markdown')->where('embedding_status', 'ready')->count()
                : 0;

            return response()->json([
                'data' => [
                    'enabled' => (bool) config('ai_helper.enabled'),
                    'configured' => trim((string) config('ai_helper.api_key')) !== '',
                    'queue' => [
                        'default_connection' => config('queue.default'),
                    ],
                    'knowledge_runtime' => [
                        'mode' => 'markdown_only',
                        'pdf_ingestion_enabled' => false,
                        'external_ocr_required' => false,
                        'retrieval_mode' => (bool) config('ai_helper.retrieval_v2', true) ? 'hybrid' : 'legacy',
                        'retrieval_pipeline_version' => (bool) config('ai_helper.retrieval_v3', false) ? 3 : 2,
                        'rerank_enabled' => (bool) config('ai_helper.rerank_enabled', false),
                        'critical_fact_validation_enabled' => (bool) config('ai_helper.critical_fact_validation_enabled', true),
                        'grounding_verification_mode' => (string) config('ai_helper.grounding_verification_mode', 'disabled'),
                        'retrieval_schema_ready' => $retrievalSchemaReady,
                        'semantic_ready' => $retrievalSchemaReady && $activeChunks > 0 && $activeChunks === $embeddedChunks,
                        'markdown_sources' => $markdownSources,
                        'semantic_sources' => $semanticSources,
                        'chunks' => $activeChunks,
                        'embedded_chunks' => $embeddedChunks,
                        'missing_embeddings' => max(0, $activeChunks - $embeddedChunks),
                    ],
                    'storage' => [
                        'knowledge_used_bytes' => $knowledgeStorageBytes,
                        'knowledge_max_total_bytes' => (int) config('ai_helper.knowledge_max_total_upload_bytes', 0),
                        'document_used_bytes' => (int) AiHelperDocument::withTrashed()->sum('source_size'),
                        'document_max_total_bytes' => (int) config('ai_helper.document_max_total_upload_bytes', 0),
                    ],
                    'reliability' => $this->reliabilityMetrics->recent(),
                    'recent_failed_uploads' => $failedUploads,
                ],
                'request_id' => $this->responder->requestId($request),
            ]);
        } catch (Throwable $e) {
            return $this->safeFailure($request, $e, 'diagnostics');
        }
    }

    public function adminKnowledgeDetail(Request $request, int $knowledgeId): JsonResponse
    {
        if ($response = $this->authorizeSystemAdministrator($request)) {
            return $response;
        }

        try {
            $entry = AiHelperKnowledgeEntry::query()
                ->where('source_mime', 'text/markdown')
                ->with([
                    'uploader:id,name,email',
                    'reviewer:id,name,email',
                    'sourceDocument:id,title',
                ])
                ->withCount('chunks')
                ->find($knowledgeId);

            if (! $entry) {
                return response()->json([
                    'message' => 'Knowledge entry not found.',
                    'code' => 'AI_HELPER_KNOWLEDGE_NOT_FOUND',
                ], 404);
            }

            return response()->json(['data' => $this->formatKnowledgeEntry($entry)]);
        } catch (Throwable $e) {
            return $this->safeFailure($request, $e, 'admin_knowledge_detail');
        }
    }

    public function updateAdminKnowledge(UpdateAiHelperAdminKnowledgeRequest $request, int $knowledgeId): JsonResponse
    {
        if ($response = $this->authorizeSystemAdministrator($request)) {
            return $response;
        }

        $validated = $request->validated();

        try {
            $entry = AiHelperKnowledgeEntry::query()
                ->where('source_mime', 'text/markdown')
                ->find($knowledgeId);
            if (! $entry) {
                return response()->json([
                    'message' => 'Knowledge entry not found.',
                    'code' => 'AI_HELPER_KNOWLEDGE_NOT_FOUND',
                ], 404);
            }
            if ($entry->knowledge_type === AiHelperKnowledgeEntry::KNOWLEDGE_SYSTEM_GUIDE) {
                return response()->json([
                    'message' => 'Code-controlled system guides can only be changed by deployment and reseeding.',
                    'code' => 'AI_HELPER_SYSTEM_GUIDE_CODE_CONTROLLED',
                ], 409);
            }

            $updates = [
                'review_note' => trim((string) ($validated['review_note'] ?? '')) ?: null,
            ];

            if (($validated['review_status'] ?? null) !== null) {
                $reviewStatus = (string) $validated['review_status'];
                $updates['review_status'] = $reviewStatus;
                $updates['reviewed_by'] = $request->user()->id;
                $updates['reviewed_at'] = now();

                if ($reviewStatus === AiHelperKnowledgeEntry::REVIEW_REJECTED) {
                    $updates['status'] = AiHelperKnowledgeEntry::STATUS_DISABLED;
                    $updates['active'] = false;
                }

                if (
                    $reviewStatus === AiHelperKnowledgeEntry::REVIEW_APPROVED &&
                    $entry->extraction_complete &&
                    ! in_array($entry->status, [AiHelperKnowledgeEntry::STATUS_PROCESSING, AiHelperKnowledgeEntry::STATUS_FAILED], true)
                ) {
                    $updates['status'] = AiHelperKnowledgeEntry::STATUS_ACTIVE;
                    $updates['active'] = true;
                }
            }

            if (($validated['status'] ?? null) !== null) {
                $status = (string) $validated['status'];
                if ($status === AiHelperKnowledgeEntry::STATUS_ACTIVE && ! $entry->extraction_complete) {
                    return response()->json([
                        'message' => 'Knowledge extraction must be complete before it can be enabled.',
                        'code' => 'AI_HELPER_KNOWLEDGE_NOT_READY',
                    ], 422);
                }
                if ($status === AiHelperKnowledgeEntry::STATUS_ACTIVE && $entry->review_status !== AiHelperKnowledgeEntry::REVIEW_APPROVED && ($updates['review_status'] ?? null) !== AiHelperKnowledgeEntry::REVIEW_APPROVED) {
                    return response()->json([
                        'message' => 'Knowledge must be approved before it can be enabled.',
                        'code' => 'AI_HELPER_KNOWLEDGE_NOT_APPROVED',
                    ], 422);
                }
                if (in_array($entry->status, [AiHelperKnowledgeEntry::STATUS_PROCESSING, AiHelperKnowledgeEntry::STATUS_FAILED], true)) {
                    return response()->json([
                        'message' => 'Processing or failed knowledge cannot be manually enabled.',
                        'code' => 'AI_HELPER_KNOWLEDGE_NOT_READY',
                    ], 422);
                }
                $updates['status'] = $status;
                $updates['active'] = $status === AiHelperKnowledgeEntry::STATUS_ACTIVE;
            }

            $entry->forceFill($updates)->save();
            AuditLogger::log($request, 'ai_helper_admin_knowledge_updated', $request->user(), [
                'knowledge_entry_id' => $entry->id,
                'updates' => array_keys($updates),
                'request_id' => $this->responder->requestId($request),
            ]);

            return response()->json([
                'message' => 'Knowledge review updated.',
                'data' => $this->formatKnowledgeEntry($entry->fresh(['uploader', 'reviewer', 'sourceDocument'])),
                'request_id' => $this->responder->requestId($request),
            ]);
        } catch (Throwable $e) {
            return $this->safeFailure($request, $e, 'admin_knowledge_update');
        }
    }

    public function destroyAdminKnowledge(Request $request, int $knowledgeId): JsonResponse
    {
        if ($response = $this->authorizeSystemAdministrator($request)) {
            return $response;
        }

        try {
            $entry = AiHelperKnowledgeEntry::query()
                ->where('source_mime', 'text/markdown')
                ->find($knowledgeId);
            if (! $entry) {
                return response()->json([
                    'message' => 'Knowledge entry not found.',
                    'code' => 'AI_HELPER_KNOWLEDGE_NOT_FOUND',
                ], 404);
            }
            if ($entry->knowledge_type === AiHelperKnowledgeEntry::KNOWLEDGE_SYSTEM_GUIDE) {
                return response()->json([
                    'message' => 'Code-controlled system guides cannot be deleted through the API.',
                    'code' => 'AI_HELPER_SYSTEM_GUIDE_CODE_CONTROLLED',
                ], 409);
            }

            $this->knowledgeLifecycle->purge($entry);
            AuditLogger::log($request, 'ai_helper_admin_knowledge_deleted', $request->user(), [
                'knowledge_entry_id' => $entry->id,
                'request_id' => $this->responder->requestId($request),
            ]);

            return response()->json([
                'message' => 'Knowledge deleted.',
                'request_id' => $this->responder->requestId($request),
            ]);
        } catch (Throwable $e) {
            return $this->safeFailure($request, $e, 'admin_knowledge_delete');
        }
    }

    public function stream(StreamAiHelperMessageRequest $request): StreamedResponse|JsonResponse
    {
        $validated = $request->validated();
        $requestId = $this->responder->requestId($request);

        if (! $this->openAi->isAvailable()) {
            return $this->responder->error(
                $request,
                'Ask AI is not ready yet. Please contact an administrator.',
                'AI_HELPER_UNAVAILABLE',
                503,
            );
        }

        $corpus = $this->knowledge->corpusReadiness();
        if ((bool) config('ai_helper.knowledge_strict_readiness', true) && ! ($corpus['ready'] ?? false)) {
            return $this->responder->error(
                $request,
                'Ask AI is waiting for the uploaded knowledge corpus to finish processing. Resolve failed documents or wait for ingestion to complete.',
                'AI_HELPER_KNOWLEDGE_NOT_READY',
                409,
                ['corpus' => $corpus],
            );
        }

        try {
            $actor = $request->user();
            $conversationPurpose = $this->normalizeConversationPurpose($validated['conversation_purpose'] ?? null);
            $retrievalThread = null;
            if ($conversationPurpose === self::CONVERSATION_PURPOSE_CHAT && ! (bool) ($validated['new_thread'] ?? false)) {
                $threadQuery = AiHelperThread::query()->where('user_id', $actor->id);
                $retrievalThread = ! empty($validated['thread_id'])
                    ? $this->regularThreadById($threadQuery, (int) $validated['thread_id'])
                    : $this->latestRegularThreadForUser($actor->id);
            }
            $previousUserMessages = $this->conversation->recentUserMessages($retrievalThread);
            $pageContext = $this->knowledge->buildContext(
                $validated['page_context'] ?? [],
                $actor,
                (string) $validated['message'],
                $previousUserMessages,
            );
            $pageContext['page']['conversation_purpose'] = $conversationPurpose;
            $pageContext['page']['assistant_surface'] = $conversationPurpose;
            $responseLanguage = (string) ($validated['response_language'] ?? 'bm');
            $instructions = $this->knowledge->instructionsFor(
                $pageContext,
                $responseLanguage,
            );
            $sources = $this->knowledge->citationsForGuidance($pageContext['guidance'] ?? []);
            $deterministicContent = $this->knowledge->deterministicResponseFor(
                $pageContext,
                $responseLanguage,
            );

            if ($conversationPurpose === self::CONVERSATION_PURPOSE_EMBEDDED_HELPER) {
                if (! empty($validated['thread_id'])) {
                    return $this->responder->error(
                        $request,
                        'Embedded AI helpers do not use chat threads.',
                        'AI_HELPER_EMBEDDED_THREAD_FORBIDDEN',
                        422,
                    );
                }

                $history = [[
                    'role' => AiHelperMessage::ROLE_USER,
                    'content' => (string) $validated['message'],
                ]];

                Log::info('Ask AI embedded helper stream prepared', [
                    'request_id' => $requestId,
                    'user_id' => $actor->id,
                    'guidance_count' => count($pageContext['guidance'] ?? []),
                ]);

                return $this->streamEmbeddedHelperResponse(
                    (string) $validated['message'],
                    $history,
                    $instructions,
                    $requestId,
                    $actor->id,
                    count($pageContext['guidance'] ?? []),
                    $pageContext['page'] ?? [],
                    $sources,
                    $pageContext['guidance'] ?? [],
                    $pageContext['retrieval'] ?? [],
                    $deterministicContent,
                    $responseLanguage,
                );
            }

            $thread = $this->resolveThread(
                $actor->id,
                $validated['thread_id'] ?? null,
                (bool) ($validated['new_thread'] ?? false),
                $validated['message'],
                $pageContext['page'] ?? [],
                $conversationPurpose,
            );

            $userMessage = $thread->messages()->create([
                'role' => AiHelperMessage::ROLE_USER,
                'content' => $validated['message'],
                'route_context' => $pageContext['page'] ?? [],
                'status' => AiHelperMessage::STATUS_COMPLETED,
            ]);

            $assistantMessage = $thread->messages()->create([
                'role' => AiHelperMessage::ROLE_ASSISTANT,
                'content' => '',
                'route_context' => $pageContext['page'] ?? [],
                'status' => AiHelperMessage::STATUS_STREAMING,
                'retrieval_metadata' => $pageContext['retrieval'] ?? [],
            ]);

            $thread->forceFill([
                'latest_route_context' => $pageContext['page'] ?? [],
            ])->save();

            $history = $this->conversation->inputForThread($thread, $userMessage->id);
            Log::info('Ask AI stream prepared', [
                'request_id' => $requestId,
                'thread_id' => $thread->id,
                'user_id' => $actor->id,
                'guidance_count' => count($pageContext['guidance'] ?? []),
            ]);
        } catch (Throwable $e) {
            return $this->safeFailure($request, $e, 'stream_prepare');
        }

        $question = (string) $validated['message'];
        $guidance = $pageContext['guidance'] ?? [];

        return response()->stream(function () use ($thread, $assistantMessage, $history, $instructions, $requestId, $sources, $guidance, $question, $deterministicContent, $responseLanguage) {
            $this->emit('meta', [
                'request_id' => $requestId,
                'contract_version' => 1,
                'thread' => $this->formatThread($thread->fresh()),
                'message_id' => $assistantMessage->id,
            ], $requestId);
            $this->emit('heartbeat', ['request_id' => $requestId, 'at' => now()->toIso8601String()], $requestId);

            $content = '';
            try {
                $startedAt = microtime(true);
                $lastHeartbeatAt = microtime(true);
                $result = $this->responsePipeline->respond(
                    $question,
                    $instructions,
                    $history,
                    $guidance,
                    $sources,
                    $deterministicContent,
                    $responseLanguage,
                    fn (string $status) => $this->emitPipelineStatus($status, $requestId),
                    function (string $delta) use (&$lastHeartbeatAt, $requestId) {
                        if (connection_aborted()) {
                            throw new RuntimeException('AI helper stream aborted by client.');
                        }
                        if (microtime(true) - $lastHeartbeatAt >= 10) {
                            $this->emit('heartbeat', ['request_id' => $requestId, 'at' => now()->toIso8601String()], $requestId);
                            $lastHeartbeatAt = microtime(true);
                        }
                    },
                );
                $content = (string) $result['content'];
                $responseSources = $result['sources'] ?? [];
                $verification = $result['verification'] ?? [];
                $retrievalMetadata = array_merge($assistantMessage->retrieval_metadata ?? [], [
                    'pipeline_version' => (int) (($assistantMessage->retrieval_metadata['pipeline_version'] ?? null) ?: 3),
                    'verification' => $verification,
                    'citation_validation' => $verification['citation_validation'] ?? null,
                    'provider_response_ids' => $result['provider_response_ids'] ?? [],
                    'response_timings_ms' => $result['timings_ms'] ?? [],
                ]);
                $this->emit('delta', ['text' => $content, 'request_id' => $requestId], $requestId);

                $assistantMessage->forceFill([
                    'content' => $content,
                    'openai_response_id' => $result['response_id'] ?? null,
                    'status' => AiHelperMessage::STATUS_COMPLETED,
                    'error' => null,
                    'sources' => $responseSources,
                    'retrieval_metadata' => $retrievalMetadata,
                ])->save();

                $thread->touch();
                Log::info('Ask AI stream completed', [
                    'request_id' => $requestId,
                    'thread_id' => $thread->id,
                    'assistant_message_id' => $assistantMessage->id,
                    'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
                    'content_length' => strlen($content),
                    'citation_validation_status' => $verification['citation_validation']['status'] ?? null,
                    'verification_status' => $verification['status'] ?? null,
                    'verification_attempts' => $verification['attempts'] ?? null,
                ]);
                $this->emit('done', [
                    'request_id' => $requestId,
                    'thread' => $this->formatThread($thread->fresh()),
                    'message' => $this->formatMessage($assistantMessage->fresh()),
                ], $requestId);
            } catch (Throwable $e) {
                $aborted = str_contains($e->getMessage(), 'aborted by client') || connection_aborted();
                $assistantMessage->forceFill([
                    'content' => $content,
                    'status' => $aborted ? AiHelperMessage::STATUS_ABORTED : AiHelperMessage::STATUS_FAILED,
                    'error' => Str::limit($e->getMessage(), 1000, ''),
                ])->save();
                Log::warning('Ask AI stream failed', [
                    'request_id' => $requestId,
                    'thread_id' => $thread->id,
                    'assistant_message_id' => $assistantMessage->id,
                    'error' => $e->getMessage(),
                ]);

                $this->emit($aborted ? 'done' : 'error', [
                    'request_id' => $requestId,
                    'message' => $aborted ? 'Ask AI response stopped.' : 'Ask AI could not generate a response. Please try again.',
                    'code' => $aborted ? 'AI_HELPER_STREAM_ABORTED' : 'AI_HELPER_STREAM_FAILED',
                ], $requestId);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
            'X-Request-Id' => $requestId,
        ]);
    }

    private function streamEmbeddedHelperResponse(
        string $question,
        array $history,
        string $instructions,
        string $requestId,
        int $userId,
        int $guidanceCount,
        array $pageContext,
        array $sources,
        array $guidance,
        array $retrievalMetadata,
        ?string $deterministicContent,
        string $responseLanguage,
    ): StreamedResponse {
        return response()->stream(function () use ($question, $history, $instructions, $requestId, $userId, $guidanceCount, $pageContext, $sources, $guidance, $retrievalMetadata, $deterministicContent, $responseLanguage) {
            $this->emit('meta', [
                'request_id' => $requestId,
                'contract_version' => 1,
                'conversation_purpose' => self::CONVERSATION_PURPOSE_EMBEDDED_HELPER,
                'thread' => null,
                'message_id' => null,
            ], $requestId);
            $this->emit('heartbeat', ['request_id' => $requestId, 'at' => now()->toIso8601String()], $requestId);

            $content = '';
            try {
                $startedAt = microtime(true);
                $lastHeartbeatAt = microtime(true);
                $result = $this->responsePipeline->respond(
                    $question,
                    $instructions,
                    $history,
                    $guidance,
                    $sources,
                    $deterministicContent,
                    $responseLanguage,
                    fn (string $status) => $this->emitPipelineStatus($status, $requestId),
                    function (string $delta) use (&$lastHeartbeatAt, $requestId) {
                        if (connection_aborted()) {
                            throw new RuntimeException('AI helper stream aborted by client.');
                        }
                        if (microtime(true) - $lastHeartbeatAt >= 10) {
                            $this->emit('heartbeat', ['request_id' => $requestId, 'at' => now()->toIso8601String()], $requestId);
                            $lastHeartbeatAt = microtime(true);
                        }
                    },
                );
                $content = (string) $result['content'];
                $responseSources = $result['sources'] ?? [];
                $verification = $result['verification'] ?? [];
                $this->emit('delta', ['text' => $content, 'request_id' => $requestId], $requestId);

                Log::info('Ask AI embedded helper stream completed', [
                    'request_id' => $requestId,
                    'user_id' => $userId,
                    'openai_response_id' => $result['response_id'] ?? null,
                    'guidance_count' => $guidanceCount,
                    'retrieval_mode' => $retrievalMetadata['mode'] ?? null,
                    'retrieval_document_count' => $retrievalMetadata['documents_selected'] ?? null,
                    'retrieval_chunk_count' => $retrievalMetadata['chunks_selected'] ?? null,
                    'semantic_fallback' => $retrievalMetadata['semantic_fallback'] ?? null,
                    'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
                    'content_length' => strlen($content),
                    'citation_validation_status' => $verification['citation_validation']['status'] ?? null,
                    'verification_status' => $verification['status'] ?? null,
                    'verification_attempts' => $verification['attempts'] ?? null,
                ]);

                $this->emit('done', [
                    'request_id' => $requestId,
                    'conversation_purpose' => self::CONVERSATION_PURPOSE_EMBEDDED_HELPER,
                    'thread' => null,
                    'message' => $this->formatTransientAssistantMessage($content, $pageContext, $responseSources),
                ], $requestId);
            } catch (Throwable $e) {
                $aborted = str_contains($e->getMessage(), 'aborted by client') || connection_aborted();
                Log::warning('Ask AI embedded helper stream failed', [
                    'request_id' => $requestId,
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);

                $this->emit($aborted ? 'done' : 'error', [
                    'request_id' => $requestId,
                    'conversation_purpose' => self::CONVERSATION_PURPOSE_EMBEDDED_HELPER,
                    'message' => $aborted ? 'Ask AI response stopped.' : 'Ask AI could not generate a response. Please try again.',
                    'code' => $aborted ? 'AI_HELPER_STREAM_ABORTED' : 'AI_HELPER_STREAM_FAILED',
                ], $requestId);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
            'X-Request-Id' => $requestId,
        ]);
    }

    private function safeFailure(Request $request, Throwable $e, string $action): JsonResponse
    {
        return $this->responder->failure($request, $e, $action);
    }

    private function resolveThread(
        int $userId,
        ?int $threadId,
        bool $newThread,
        string $message,
        array $pageContext,
        string $conversationPurpose = self::CONVERSATION_PURPOSE_CHAT,
    ): AiHelperThread {
        if ($threadId) {
            $thread = AiHelperThread::query()
                ->where('user_id', $userId)
                ->where('id', $threadId)
                ->firstOrFail();

            if ($this->threadConversationPurpose($thread) !== $conversationPurpose) {
                abort(404);
            }

            return $thread;
        }

        if (! $newThread && $conversationPurpose === self::CONVERSATION_PURPOSE_CHAT) {
            $latest = $this->latestRegularThreadForUser($userId);
            if ($latest) {
                return $latest;
            }
        }

        return AiHelperThread::create([
            'user_id' => $userId,
            'title' => $this->buildThreadTitle($message, $pageContext),
            'conversation_purpose' => $conversationPurpose,
            'latest_route_context' => $pageContext,
        ]);
    }

    private function regularThreadById($query, int $threadId): ?AiHelperThread
    {
        $thread = $query->where('id', $threadId)->first();

        return $thread && $this->isRegularChatThread($thread) ? $thread : null;
    }

    private function latestRegularThreadForUser(int $userId): ?AiHelperThread
    {
        return AiHelperThread::query()
            ->where('user_id', $userId)
            ->where(function ($query) {
                $query->whereNull('conversation_purpose')
                    ->orWhere('conversation_purpose', 'chat');
            })
            ->latest('updated_at')
            ->limit(80)
            ->get()
            ->first(fn (AiHelperThread $thread) => $this->isRegularChatThread($thread));
    }

    private function normalizeConversationPurpose(mixed $value): string
    {
        return $value === self::CONVERSATION_PURPOSE_EMBEDDED_HELPER
            ? self::CONVERSATION_PURPOSE_EMBEDDED_HELPER
            : self::CONVERSATION_PURPOSE_CHAT;
    }

    private function isRegularChatThread(AiHelperThread $thread): bool
    {
        return $this->threadConversationPurpose($thread) === self::CONVERSATION_PURPOSE_CHAT;
    }

    private function threadConversationPurpose(AiHelperThread $thread): string
    {
        $storedPurpose = $this->normalizeConversationPurpose($thread->conversation_purpose ?? null);
        if ($storedPurpose !== 'chat' || $thread->conversation_purpose !== null) {
            return $storedPurpose;
        }

        $context = is_array($thread->latest_route_context) ? $thread->latest_route_context : [];
        $purpose = Str::lower(trim((string) ($context['conversation_purpose'] ?? $context['assistant_surface'] ?? '')));

        if (in_array($purpose, [self::CONVERSATION_PURPOSE_EMBEDDED_HELPER, 'module_helper', 'inspection_helper', 'erco_helper'], true)) {
            return self::CONVERSATION_PURPOSE_EMBEDDED_HELPER;
        }

        return $this->looksLikeLegacyEmbeddedHelperThread($thread, $context)
            ? self::CONVERSATION_PURPOSE_EMBEDDED_HELPER
            : self::CONVERSATION_PURPOSE_CHAT;
    }

    private function looksLikeLegacyEmbeddedHelperThread(AiHelperThread $thread, array $context): bool
    {
        $title = Str::lower(trim((string) $thread->title));
        if ($title === '') {
            return false;
        }

        $moduleKey = Str::lower(trim((string) ($context['module_key'] ?? '')));
        $path = Str::lower(trim((string) ($context['path'] ?? '')));
        $isInspectionContext = $moduleKey === 'inspection' || str_starts_with($path, '/inspection');
        $isReportContext = $moduleKey === 'reports' || str_starts_with($path, '/report/erco');

        if ($isInspectionContext && str_starts_with($title, 'translate and polish this general/hse inspection')) {
            return true;
        }

        if ($isReportContext) {
            foreach ([
                'generate an erco emergency response incident summary',
                'improve the existing erco emergency response incident summary',
                'check this erco report for possible missing',
            ] as $prefix) {
                if (str_starts_with($title, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function buildThreadTitle(string $message, array $pageContext): string
    {
        $clean = trim((string) preg_replace('/\s+/', ' ', $message));
        $normalized = Str::lower(trim($clean, " \t\n\r\0\x0B.!?"));
        $genericPrompts = [
            'hi',
            'hello',
            'hey',
            'test',
            'thanks',
            'thank you',
            'ok',
            'okay',
        ];

        if ($clean === '' || Str::length($normalized) < 12 || in_array($normalized, $genericPrompts, true)) {
            return $this->pageTitle($pageContext);
        }

        $withoutGreeting = preg_replace('/^(hi|hello|hey|ok|okay)[,\s]+/i', '', $clean);
        $withoutFiller = preg_replace('/\b(please|can you|could you|tell me|explain|help me)\b/i', '', $withoutGreeting);
        $title = trim((string) preg_replace('/\s+/', ' ', $withoutFiller), " \t\n\r\0\x0B.!?");

        if ($title === '' || Str::length($title) < 8) {
            return $this->pageTitle($pageContext);
        }

        return Str::headline(Str::limit($title, 56, ''));
    }

    private function pageTitle(array $pageContext): string
    {
        $title = trim((string) ($pageContext['title'] ?? $pageContext['route_name'] ?? ''));
        if ($title === '') {
            $title = trim((string) ($pageContext['module_key'] ?? $pageContext['route_key'] ?? ''));
        }

        return Str::limit(Str::headline($title ?: 'VMECC').' help', 80, '');
    }

    private function emitPipelineStatus(string $status, string $requestId): void
    {
        $message = match ($status) {
            'generating' => 'Preparing an answer from the selected knowledge...',
            'verifying' => 'Checking the answer against its sources...',
            'repairing' => 'Correcting an answer that did not pass source checks...',
            default => 'Processing the Ask AI request...',
        };

        $this->emit('status', [
            'request_id' => $requestId,
            'status' => $status,
            'message' => $message,
        ], $requestId);
    }

    private function emit(string $event, array $payload, ?string $requestId = null): void
    {
        if ($requestId && ! isset($payload['request_id'])) {
            $payload['request_id'] = $requestId;
        }
        echo "event: {$event}\n";
        echo 'data: '.json_encode($payload, JSON_UNESCAPED_SLASHES)."\n\n";
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        flush();
    }

    private function formatThread(?AiHelperThread $thread): ?array
    {
        if (! $thread) {
            return null;
        }

        return [
            'id' => $thread->id,
            'title' => $thread->title ?: 'Ask AI chat',
            'conversation_purpose' => $this->threadConversationPurpose($thread),
            'latest_route_context' => $thread->latest_route_context ?: [],
            'updated_at' => optional($thread->updated_at)->toIso8601String(),
        ];
    }

    private function formatMessage(AiHelperMessage $message): array
    {
        return [
            'id' => $message->id,
            'role' => $message->role,
            'content' => (string) $message->content,
            'status' => $message->status,
            'route_context' => $message->route_context ?: [],
            'sources' => $message->sources ?: [],
            'created_at' => optional($message->created_at)->toIso8601String(),
        ];
    }

    private function formatTransientAssistantMessage(string $content, array $pageContext, array $sources = []): array
    {
        return [
            'id' => null,
            'role' => AiHelperMessage::ROLE_ASSISTANT,
            'content' => $content,
            'status' => AiHelperMessage::STATUS_COMPLETED,
            'route_context' => $pageContext,
            'sources' => $sources,
            'created_at' => now()->toIso8601String(),
        ];
    }

    private function decodeKnowledgePageContext(mixed $pageContext): array
    {
        if (is_array($pageContext)) {
            return $pageContext;
        }

        if (is_string($pageContext)) {
            $decoded = json_decode($pageContext, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function visibleDocumentsQuery(User $user)
    {
        return AiHelperDocument::query()
            ->where(function ($query) use ($user) {
                $query
                    ->where('uploaded_by', $user->id)
                    ->orWhere('visibility', AiHelperDocument::VISIBILITY_SHARED);
            });
    }

    private function resolveVisibleDocument(User $user, int $documentId): ?AiHelperDocument
    {
        return $this->visibleDocumentsQuery($user)
            ->where('id', $documentId)
            ->first();
    }

    private function formatDocument(AiHelperDocument $document): array
    {
        $uploader = $document->relationLoaded('uploader') ? $document->uploader : null;
        $sourcePath = trim((string) ($document->source_path ?? ''));

        return [
            'id' => $document->id,
            'title' => $document->title,
            'source_filename' => $document->source_filename,
            'source_mime' => 'application/pdf',
            'source_size' => $document->source_size,
            'visibility' => $document->visibility,
            'uploaded_by' => $document->uploaded_by,
            'uploader_name' => $uploader?->name ?: $uploader?->email ?: ($document->uploaded_by ? 'Unknown user' : 'System'),
            'original_available' => $sourcePath !== '' && Storage::disk('local')->exists($sourcePath),
            'ai_usable' => false,
            'kind' => 'reference_pdf',
            'acknowledged_at' => optional($document->acknowledged_at)->toIso8601String(),
            'created_at' => optional($document->created_at)->toIso8601String(),
            'updated_at' => optional($document->updated_at)->toIso8601String(),
        ];
    }

    private function formatKnowledgeEntry(AiHelperKnowledgeEntry $entry): array
    {
        $uploader = $entry->relationLoaded('uploader') ? $entry->uploader : null;
        $uploaderName = $uploader?->name ?: $uploader?->email ?: null;

        return [
            'id' => $entry->id,
            'title' => $entry->title,
            'knowledge_type' => $entry->knowledge_type,
            'guide_key' => $entry->knowledge_type === AiHelperKnowledgeEntry::KNOWLEDGE_SYSTEM_GUIDE
                ? Str::after((string) $entry->source_path, 'seed:system-guide:')
                : null,
            'guide_version' => $entry->knowledge_type === AiHelperKnowledgeEntry::KNOWLEDGE_SYSTEM_GUIDE
                ? (int) $entry->version
                : null,
            'guide_owner' => $entry->guide_owner,
            'module_gate' => $entry->module_gate,
            'access_rule' => $entry->knowledge_type === AiHelperKnowledgeEntry::KNOWLEDGE_SYSTEM_GUIDE
                ? [
                    'permission_match' => $entry->permission_match,
                    'required_permissions' => $entry->required_permissions ?? [],
                    'allowed_roles' => $entry->allowed_roles ?? [],
                ]
                : null,
            'review_due_at' => optional($entry->review_due_at)->toIso8601String(),
            'module_key' => $entry->module_key,
            'route_key' => $entry->route_key,
            'scope_type' => $entry->scope_type,
            'status' => $entry->status ?: AiHelperKnowledgeEntry::STATUS_ACTIVE,
            'visibility' => $entry->visibility ?: AiHelperKnowledgeEntry::VISIBILITY_SHARED,
            'review_status' => $entry->review_status ?: AiHelperKnowledgeEntry::REVIEW_APPROVED,
            'active' => (bool) $entry->active,
            'uploaded_by' => $entry->uploaded_by,
            'uploader_name' => $uploaderName ?: ($entry->uploaded_by ? 'Unknown user' : 'System'),
            'reviewer' => $this->formatUser($entry->relationLoaded('reviewer') ? $entry->reviewer : null),
            'review_note' => (string) ($entry->review_note ?? ''),
            'chunks_count' => $entry->chunks_count ?? null,
            'source_filename' => $entry->knowledge_type === AiHelperKnowledgeEntry::KNOWLEDGE_SYSTEM_GUIDE
                ? null
                : $entry->source_filename,
            'source_mime' => $entry->source_mime,
            'source_size' => $entry->source_size,
            'source_document_id' => $entry->source_document_id,
            'embedding_status' => $entry->embedding_status,
            'embedded_at' => optional($entry->embedded_at)->toIso8601String(),
            'source_document_title' => $entry->relationLoaded('sourceDocument') ? $entry->sourceDocument?->title : null,
            'ingestion_run_id' => $entry->ingestion_run_id,
            'ingestion_version' => $entry->ingestion_version,
            'ingestion_started_at' => optional($entry->ingestion_started_at)->toIso8601String(),
            'ingestion_completed_at' => optional($entry->ingestion_completed_at)->toIso8601String(),
            'extraction_complete' => (bool) $entry->extraction_complete,
            'quality_status' => $entry->quality_status,
            'extracted_characters' => (int) ($entry->extracted_characters ?? 0),
            'error' => $entry->error,
            'acknowledged_at' => optional($entry->acknowledged_at)->toIso8601String(),
            'processed_at' => optional($entry->processed_at)->toIso8601String(),
            'reviewed_at' => optional($entry->reviewed_at)->toIso8601String(),
            'created_at' => optional($entry->created_at)->toIso8601String(),
            'updated_at' => optional($entry->updated_at)->toIso8601String(),
        ];
    }

    private function authorizeSystemAdministrator(Request $request): ?JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($this->authorization->isSystemAdministrator($user)) {
            return null;
        }

        return $this->responder->error(
            $request,
            'You do not have permission to manage Ask AI administration.',
            'AI_HELPER_ADMIN_FORBIDDEN',
            403,
        );
    }

    private function formatReportSummary(AiHelperResponseReport $report): array
    {
        $pageContext = $report->page_context ?: [];

        return [
            'id' => $report->id,
            'reason' => (string) $report->reason,
            'status' => $report->status,
            'reporter' => $this->formatUser($report->reporter),
            'page' => [
                'title' => $pageContext['title'] ?? $pageContext['route_name'] ?? $pageContext['route_key'] ?? null,
                'path' => $pageContext['path'] ?? $pageContext['route_path'] ?? null,
                'module_key' => $pageContext['module_key'] ?? null,
            ],
            'thread' => $this->formatThread($report->thread),
            'created_at' => optional($report->created_at)->toIso8601String(),
            'updated_at' => optional($report->updated_at)->toIso8601String(),
            'reviewed_at' => optional($report->reviewed_at)->toIso8601String(),
            'reviewer' => $this->formatUser($report->reviewer),
        ];
    }

    private function formatReportDetail(AiHelperResponseReport $report): array
    {
        return [
            ...$this->formatReportSummary($report),
            'assistant_content' => (string) $report->assistant_content,
            'preceding_user_content' => (string) ($report->preceding_user_content ?? ''),
            'page_context' => $report->page_context ?: [],
            'chat_snapshot' => $report->chat_snapshot ?: ['messages' => []],
            'openai_response_id' => $report->openai_response_id,
            'reporter_ip' => $report->reporter_ip,
            'reporter_user_agent' => $report->reporter_user_agent,
            'admin_note' => (string) ($report->admin_note ?? ''),
        ];
    }

    private function formatUser($user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name ?? null,
            'email' => $user->email ?? null,
        ];
    }
}
