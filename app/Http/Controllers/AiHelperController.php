<?php

namespace App\Http\Controllers;

use App\Http\Requests\AiHelper\ListAiHelperAdminKnowledgeRequest;
use App\Http\Requests\AiHelper\ListAiHelperReportsRequest;
use App\Http\Requests\AiHelper\ReportAiHelperMessageRequest;
use App\Http\Requests\AiHelper\StreamAiHelperMessageRequest;
use App\Http\Requests\AiHelper\UpdateAiHelperAdminKnowledgeRequest;
use App\Http\Requests\AiHelper\UpdateAiHelperKnowledgeRequest;
use App\Http\Requests\AiHelper\UpdateAiHelperReportRequest;
use App\Http\Requests\AiHelper\UploadAiHelperKnowledgeRequest;
use App\Http\Requests\AiHelper\UploadAiHelperMarkdownKnowledgeRequest;
use App\Models\AiHelperMessage;
use App\Models\AiHelperKnowledgeEntry;
use App\Models\AiHelperResponseReport;
use App\Models\AiHelperThread;
use App\Models\User;
use App\Jobs\ProcessAiHelperKnowledgeEntry;
use App\Services\AiHelperApiResponder;
use App\Services\AiHelperAuthorizationService;
use App\Services\AiHelperConversationService;
use App\Services\AiHelperKnowledgeService;
use App\Services\AiHelperOpenAiService;
use App\Services\AiHelperMarkdownKnowledgeParser;
use App\Services\AiHelperKnowledgeProcessingService;
use App\Services\AiHelperKnowledgeQuotaService;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AiHelperController extends Controller
{
    public function __construct(
        private readonly AiHelperKnowledgeService $knowledge,
        private readonly AiHelperOpenAiService $openAi,
        private readonly AiHelperAuthorizationService $authorization,
        private readonly AiHelperKnowledgeProcessingService $knowledgeProcessor,
        private readonly AiHelperMarkdownKnowledgeParser $markdownParser,
        private readonly AiHelperKnowledgeQuotaService $knowledgeQuota,
        private readonly AiHelperConversationService $conversation,
        private readonly AiHelperApiResponder $responder,
    ) {
    }

    public function context(Request $request): JsonResponse
    {
        try {
            $payload = $request->only(['path', 'route_path', 'route_name', 'title', 'search', 'params']);
            return response()->json(['data' => $this->knowledge->buildContext($payload, $request->user())]);
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
                ? $threadQuery->where('id', $threadId)->first()
                : $threadQuery->latest('updated_at')->first();

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
                ->with(['messages' => function ($query) {
                    $query->whereIn('role', [AiHelperMessage::ROLE_USER, AiHelperMessage::ROLE_ASSISTANT])
                        ->where('status', '!=', AiHelperMessage::STATUS_FAILED)
                        ->latest('created_at')
                        ->limit(1);
                }])
                ->latest('updated_at')
                ->limit(30)
                ->get()
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

    public function knowledge(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'route_key' => ['nullable', 'string', 'max:255'],
            'module_key' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $routeKey = trim((string) ($validated['route_key'] ?? ''));
            $moduleKey = trim((string) ($validated['module_key'] ?? ''));

            $entries = $this->visibleKnowledgeEntriesQuery($request->user())
                ->with(['uploader:id,name,email', 'reviewer:id,name,email'])
                ->when($routeKey !== '' || $moduleKey !== '', function ($query) use ($routeKey, $moduleKey) {
                    $query->where(function ($inner) use ($routeKey, $moduleKey) {
                        if ($routeKey !== '') {
                            $inner->orWhere('route_key', $routeKey);
                        }
                        if ($moduleKey !== '') {
                            $inner->orWhere('module_key', $moduleKey);
                        }
                        $inner->orWhere(function ($global) {
                            $global->whereNull('module_key')->whereNull('route_key');
                        });
                    });
                })
                ->orderByRaw('CASE WHEN uploaded_by = ? THEN 0 ELSE 1 END', [$request->user()->id])
                ->latest('created_at')
                ->limit(50)
                ->get()
                ->map(fn (AiHelperKnowledgeEntry $entry) => $this->formatKnowledgeEntry($entry))
                ->values();

            return response()->json(['data' => $entries]);
        } catch (Throwable $e) {
            return $this->safeFailure($request, $e, 'knowledge');
        }
    }

    public function knowledgeDetail(Request $request, int $knowledgeId): JsonResponse
    {
        try {
            $entry = $this->resolveVisibleKnowledgeEntry($request->user(), $knowledgeId);
            if (! $entry) {
                return response()->json([
                    'message' => 'Knowledge entry not found.',
                    'code' => 'AI_HELPER_KNOWLEDGE_NOT_FOUND',
                ], 404);
            }

            $entry->loadMissing(['uploader:id,name,email', 'reviewer:id,name,email']);

            return response()->json(['data' => $this->formatUserKnowledgeDetail($entry)]);
        } catch (Throwable $e) {
            return $this->safeFailure($request, $e, 'knowledge_detail');
        }
    }

    public function knowledgeFile(Request $request, int $knowledgeId)
    {
        try {
            $entry = $this->resolveVisibleKnowledgeEntry($request->user(), $knowledgeId);
            if (! $entry) {
                return response()->json([
                    'message' => 'Knowledge entry not found.',
                    'code' => 'AI_HELPER_KNOWLEDGE_NOT_FOUND',
                ], 404);
            }

            $sourcePath = trim((string) ($entry->source_path ?? ''));

            if ($sourcePath === '' || str_starts_with($sourcePath, 'seed:')) {
                return response()->json([
                    'message' => 'Knowledge file not found.',
                    'code' => 'AI_HELPER_KNOWLEDGE_FILE_NOT_FOUND',
                ], 404);
            }

            if (! Storage::disk('local')->exists($sourcePath)) {
                return response()->json([
                    'message' => 'Knowledge file not found.',
                    'code' => 'AI_HELPER_KNOWLEDGE_FILE_NOT_FOUND',
                ], 404);
            }

            $contentType = Str::startsWith((string) $entry->source_mime, 'text/')
                ? sprintf('%s; charset=UTF-8', $entry->source_mime ?: 'text/plain')
                : ((string) $entry->source_mime ?: 'application/octet-stream');

            return response()->file(
                Storage::disk('local')->path($sourcePath),
                [
                    'Content-Type' => $contentType,
                    'Content-Disposition' => 'inline; filename="' . ($entry->source_filename ?: 'knowledge') . '"',
                ],
            );
        } catch (Throwable $e) {
            return $this->safeFailure($request, $e, 'knowledge_file');
        }
    }

    public function uploadKnowledge(UploadAiHelperKnowledgeRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $actor = $request->user();
            $scopeType = (string) $validated['scope_type'];
            $visibility = (string) ($validated['visibility'] ?? AiHelperKnowledgeEntry::VISIBILITY_PERSONAL);
            $routeKey = '';
            $moduleKey = $scopeType === AiHelperKnowledgeEntry::SCOPE_MODULE
                ? trim((string) ($validated['module_key'] ?? ''))
                : '';

            if (
                $scopeType === AiHelperKnowledgeEntry::SCOPE_MODULE &&
                ! in_array($moduleKey, AiHelperKnowledgeEntry::USER_UPLOAD_MODULE_KEYS, true)
            ) {
                return response()->json([
                    'message' => 'Choose a valid module for this knowledge source.',
                    'code' => 'AI_HELPER_KNOWLEDGE_INVALID_MODULE',
                ], 422);
            }

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

            $sourceFilename = $file->getClientOriginalName() ?: 'knowledge.pdf';
            $storedPath = $file->store("ai-helper/knowledge/{$actor->id}", 'local');
            $title = trim((string) ($validated['title'] ?? ''));
            if ($title === '') {
                $title = pathinfo($sourceFilename, PATHINFO_FILENAME) ?: 'Uploaded knowledge';
            }
            $title = Str::limit($title, 140, '');
            $reviewStatus = AiHelperKnowledgeEntry::REVIEW_APPROVED;

            $entry = AiHelperKnowledgeEntry::create([
                'uploaded_by' => $actor->id,
                'module_key' => $moduleKey !== '' ? $moduleKey : null,
                'route_key' => $routeKey !== '' ? $routeKey : null,
                'title' => $title,
                'content' => '',
                'summary' => null,
                'source_filename' => Str::limit($sourceFilename, 255, ''),
                'source_mime' => Str::limit((string) ($file->getClientMimeType() ?: 'application/pdf'), 120, ''),
                'source_size' => $file->getSize(),
                'source_path' => $storedPath,
                'scope_type' => $scopeType,
                'visibility' => $visibility,
                'status' => AiHelperKnowledgeEntry::STATUS_PROCESSING,
                'review_status' => $reviewStatus,
                'active' => false,
                'acknowledged_at' => now(),
                'error' => null,
                'tags' => array_values(array_filter(['uploaded', $scopeType, $routeKey, $moduleKey])),
                'version' => 1,
            ]);

            ProcessAiHelperKnowledgeEntry::dispatch($entry->id);
            $entry = $entry->fresh(['uploader', 'reviewer']);
            AuditLogger::log($request, 'ai_helper_knowledge_uploaded', $actor, [
                'knowledge_entry_id' => $entry->id,
                'scope_type' => $entry->scope_type,
                'visibility' => $entry->visibility,
                'source_filename' => $entry->source_filename,
                'source_size' => $entry->source_size,
                'request_id' => $this->responder->requestId($request),
            ]);

            return response()->json([
                'message' => 'Knowledge uploaded. Ask AI can use the extracted text after processing. System administrators may audit shared guidance later.',
                'data' => $this->formatKnowledgeEntry($entry),
                'request_id' => $this->responder->requestId($request),
            ], 201);
        } catch (Throwable $e) {
            return $this->safeFailure($request, $e, 'knowledge_upload');
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
                'status' => $active ? AiHelperKnowledgeEntry::STATUS_ACTIVE : AiHelperKnowledgeEntry::STATUS_DISABLED,
                'review_status' => AiHelperKnowledgeEntry::REVIEW_APPROVED,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'active' => $active,
                'acknowledged_at' => now(),
                'processed_at' => now(),
                'error' => null,
                'tags' => $tags,
                'version' => $version,
            ]);

            if ($active && ! $this->knowledgeProcessor->processTextEntry($entry, $content, $summary)) {
                $entry->forceFill([
                    'status' => AiHelperKnowledgeEntry::STATUS_FAILED,
                    'active' => false,
                    'content' => '',
                    'summary' => null,
                    'error' => 'Could not prepare readable guidance from this Markdown file.',
                    'processed_at' => now(),
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

    public function updateKnowledge(UpdateAiHelperKnowledgeRequest $request, int $knowledgeId): JsonResponse
    {
        $validated = $request->validated();

        try {
            $entry = AiHelperKnowledgeEntry::query()
                ->where('uploaded_by', $request->user()->id)
                ->where('id', $knowledgeId)
                ->first();

            if (! $entry) {
                return response()->json([
                    'message' => 'Knowledge entry not found.',
                    'code' => 'AI_HELPER_KNOWLEDGE_NOT_FOUND',
                ], 404);
            }

            if ($entry->status === AiHelperKnowledgeEntry::STATUS_PROCESSING) {
                return response()->json([
                    'message' => 'Knowledge is still processing.',
                    'code' => 'AI_HELPER_KNOWLEDGE_PROCESSING',
                ], 422);
            }

            if ($entry->status === AiHelperKnowledgeEntry::STATUS_FAILED) {
                return response()->json([
                    'message' => 'Failed knowledge entries cannot be enabled.',
                    'code' => 'AI_HELPER_KNOWLEDGE_FAILED',
                ], 422);
            }

            $status = (string) $validated['status'];
            if (
                $status === AiHelperKnowledgeEntry::STATUS_ACTIVE &&
                $entry->review_status !== AiHelperKnowledgeEntry::REVIEW_APPROVED
            ) {
                return response()->json([
                    'message' => 'Knowledge must be approved before it can be enabled.',
                    'code' => 'AI_HELPER_KNOWLEDGE_NOT_APPROVED',
                ], 422);
            }

            $entry->forceFill([
                'status' => $status,
                'active' => $status === AiHelperKnowledgeEntry::STATUS_ACTIVE,
                'error' => null,
            ])->save();
            AuditLogger::log($request, 'ai_helper_knowledge_status_updated', $request->user(), [
                'knowledge_entry_id' => $entry->id,
                'status' => $status,
                'request_id' => $this->responder->requestId($request),
            ]);

            return response()->json([
                'message' => $status === AiHelperKnowledgeEntry::STATUS_ACTIVE ? 'Knowledge enabled.' : 'Knowledge disabled.',
                'data' => $this->formatKnowledgeEntry($entry->fresh(['uploader', 'reviewer'])),
                'request_id' => $this->responder->requestId($request),
            ]);
        } catch (Throwable $e) {
            return $this->safeFailure($request, $e, 'knowledge_update');
        }
    }

    public function destroyKnowledge(Request $request, int $knowledgeId): JsonResponse
    {
        try {
            $entry = AiHelperKnowledgeEntry::query()
                ->where('uploaded_by', $request->user()->id)
                ->where('id', $knowledgeId)
                ->first();

            if (! $entry) {
                return response()->json([
                    'message' => 'Knowledge entry not found.',
                    'code' => 'AI_HELPER_KNOWLEDGE_NOT_FOUND',
                ], 404);
            }

            $entry->delete();
            AuditLogger::log($request, 'ai_helper_knowledge_deleted', $request->user(), [
                'knowledge_entry_id' => $entry->id,
                'request_id' => $this->responder->requestId($request),
            ]);

            return response()->json([
                'message' => 'Knowledge deleted.',
                'request_id' => $this->responder->requestId($request),
            ]);
        } catch (Throwable $e) {
            return $this->safeFailure($request, $e, 'knowledge_delete');
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

            if ($status !== '' && $status !== 'all' && in_array($status, AiHelperResponseReport::STATUSES, true)) {
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
            $storageBytes = (int) AiHelperKnowledgeEntry::query()
                ->withTrashed()
                ->sum('source_size');
            $failedUploads = AiHelperKnowledgeEntry::query()
                ->where('status', AiHelperKnowledgeEntry::STATUS_FAILED)
                ->latest('updated_at')
                ->limit(10)
                ->get(['id', 'title', 'source_filename', 'error', 'updated_at'])
                ->map(fn (AiHelperKnowledgeEntry $entry) => [
                    'id' => $entry->id,
                    'title' => $entry->title,
                    'source_filename' => $entry->source_filename,
                    'error' => $entry->error,
                    'updated_at' => optional($entry->updated_at)->toIso8601String(),
                ])
                ->values();

            return response()->json([
                'data' => [
                    'enabled' => (bool) config('ai_helper.enabled'),
                    'configured' => trim((string) config('ai_helper.api_key')) !== '',
                    'queue' => [
                        'default_connection' => config('queue.default'),
                    ],
                    'storage' => [
                        'used_bytes' => $storageBytes,
                        'max_total_bytes' => (int) config('ai_helper.knowledge_max_total_upload_bytes', 0),
                    ],
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
                ->with(['uploader:id,name,email', 'reviewer:id,name,email', 'chunks' => fn ($query) => $query->orderBy('chunk_index')->limit(12)])
                ->withCount('chunks')
                ->find($knowledgeId);

            if (! $entry) {
                return response()->json([
                    'message' => 'Knowledge entry not found.',
                    'code' => 'AI_HELPER_KNOWLEDGE_NOT_FOUND',
                ], 404);
            }

            return response()->json(['data' => $this->formatKnowledgeDetail($entry)]);
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
            $entry = AiHelperKnowledgeEntry::query()->find($knowledgeId);
            if (! $entry) {
                return response()->json([
                    'message' => 'Knowledge entry not found.',
                    'code' => 'AI_HELPER_KNOWLEDGE_NOT_FOUND',
                ], 404);
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
                    ! in_array($entry->status, [AiHelperKnowledgeEntry::STATUS_PROCESSING, AiHelperKnowledgeEntry::STATUS_FAILED], true)
                ) {
                    $updates['status'] = AiHelperKnowledgeEntry::STATUS_ACTIVE;
                    $updates['active'] = true;
                }
            }

            if (($validated['status'] ?? null) !== null) {
                $status = (string) $validated['status'];
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
                'data' => $this->formatKnowledgeDetail($entry->fresh(['uploader', 'reviewer', 'chunks'])),
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
            $entry = AiHelperKnowledgeEntry::query()->find($knowledgeId);
            if (! $entry) {
                return response()->json([
                    'message' => 'Knowledge entry not found.',
                    'code' => 'AI_HELPER_KNOWLEDGE_NOT_FOUND',
                ], 404);
            }

            $entry->delete();
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

        try {
            $actor = $request->user();
            $pageContext = $this->knowledge->buildContext(
                $validated['page_context'] ?? [],
                $actor,
                (string) $validated['message'],
            );
            $thread = $this->resolveThread(
                $actor->id,
                $validated['thread_id'] ?? null,
                (bool) ($validated['new_thread'] ?? false),
                $validated['message'],
                $pageContext['page'] ?? [],
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
            ]);

            $thread->forceFill([
                'latest_route_context' => $pageContext['page'] ?? [],
            ])->save();

            $history = $this->conversation->inputForThread($thread, $userMessage->id);
            $instructions = $this->knowledge->instructionsFor(
                $pageContext,
                (string) ($validated['response_language'] ?? 'bm')
            );
            Log::info('Ask AI stream prepared', [
                'request_id' => $requestId,
                'thread_id' => $thread->id,
                'user_id' => $actor->id,
                'guidance_count' => count($pageContext['guidance'] ?? []),
            ]);
        } catch (Throwable $e) {
            return $this->safeFailure($request, $e, 'stream_prepare');
        }

        return response()->stream(function () use ($thread, $assistantMessage, $history, $instructions, $requestId) {
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
                $result = $this->openAi->streamResponse($instructions, $history, function (string $delta) use (&$content, $requestId) {
                    if (connection_aborted()) {
                        throw new RuntimeException('AI helper stream aborted by client.');
                    }
                    $content .= $delta;
                    $this->emit('delta', ['text' => $delta, 'request_id' => $requestId], $requestId);
                });

                $assistantMessage->forceFill([
                    'content' => $content,
                    'openai_response_id' => $result['response_id'] ?? null,
                    'status' => AiHelperMessage::STATUS_COMPLETED,
                    'error' => null,
                ])->save();

                $thread->touch();
                Log::info('Ask AI stream completed', [
                    'request_id' => $requestId,
                    'thread_id' => $thread->id,
                    'assistant_message_id' => $assistantMessage->id,
                    'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
                    'content_length' => strlen($content),
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

    private function safeFailure(Request $request, Throwable $e, string $action): JsonResponse
    {
        return $this->responder->failure($request, $e, $action);
    }

    private function resolveThread(int $userId, ?int $threadId, bool $newThread, string $message, array $pageContext): AiHelperThread
    {
        if ($threadId) {
            $thread = AiHelperThread::query()
                ->where('user_id', $userId)
                ->where('id', $threadId)
                ->firstOrFail();

            return $thread;
        }

        if (! $newThread) {
            $latest = AiHelperThread::query()
                ->where('user_id', $userId)
                ->latest('updated_at')
                ->first();
            if ($latest) {
                return $latest;
            }
        }

        return AiHelperThread::create([
            'user_id' => $userId,
            'title' => $this->buildThreadTitle($message, $pageContext),
            'latest_route_context' => $pageContext,
        ]);
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
            'created_at' => optional($message->created_at)->toIso8601String(),
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

    private function buildKnowledgeSummary(string $content): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $content));
        if ($text === '') {
            return '';
        }

        $sentences = preg_split('/(?<=[.!?])\s+/', $text, 4) ?: [];
        $summary = trim(implode(' ', array_slice(array_filter($sentences), 0, 2)));

        if ($summary === '') {
            $summary = $text;
        }

        return Str::limit($summary, 320, '');
    }

    private function visibleKnowledgeEntriesQuery(User $user)
    {
        return AiHelperKnowledgeEntry::query()
            ->where(function ($query) use ($user) {
                $query
                    ->where('uploaded_by', $user->id)
                    ->orWhere(function ($shared) {
                        $shared
                            ->where('visibility', AiHelperKnowledgeEntry::VISIBILITY_SHARED)
                            ->where('review_status', AiHelperKnowledgeEntry::REVIEW_APPROVED)
                            ->where('status', AiHelperKnowledgeEntry::STATUS_ACTIVE)
                            ->where('active', true);
                    });
            });
    }

    private function resolveVisibleKnowledgeEntry(User $user, int $knowledgeId): ?AiHelperKnowledgeEntry
    {
        return $this->visibleKnowledgeEntriesQuery($user)
            ->where('id', $knowledgeId)
            ->first();
    }

    private function formatKnowledgeEntry(AiHelperKnowledgeEntry $entry): array
    {
        $uploader = $entry->relationLoaded('uploader') ? $entry->uploader : null;
        $uploaderName = $uploader?->name ?: $uploader?->email ?: null;

        return [
            'id' => $entry->id,
            'title' => $entry->title,
            'summary' => $entry->summary ?: ($entry->content ? $this->buildKnowledgeSummary((string) $entry->content) : null),
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
            'source_filename' => $entry->source_filename,
            'source_mime' => $entry->source_mime,
            'source_size' => $entry->source_size,
            'pdf_page_count' => $entry->pdf_page_count,
            'pdf_image_count' => $entry->pdf_image_count,
            'pdf_pages_with_images' => $entry->pdf_pages_with_images,
            'pdf_readable_text_characters' => $entry->pdf_readable_text_characters,
            'pdf_readable_word_count' => $entry->pdf_readable_word_count,
            'pdf_image_coverage_estimate' => $entry->pdf_image_coverage_estimate,
            'processing_warnings' => $entry->processing_warnings ?: [],
            'error' => $entry->error,
            'acknowledged_at' => optional($entry->acknowledged_at)->toIso8601String(),
            'processed_at' => optional($entry->processed_at)->toIso8601String(),
            'reviewed_at' => optional($entry->reviewed_at)->toIso8601String(),
            'created_at' => optional($entry->created_at)->toIso8601String(),
            'updated_at' => optional($entry->updated_at)->toIso8601String(),
        ];
    }

    private function formatKnowledgeDetail(AiHelperKnowledgeEntry $entry): array
    {
        return [
            ...$this->formatKnowledgeEntry($entry),
            'content_preview' => Str::limit((string) $entry->content, 4000, ''),
            'chunks' => $entry->relationLoaded('chunks')
                ? $entry->chunks->map(fn ($chunk) => [
                    'id' => $chunk->id,
                    'chunk_index' => $chunk->chunk_index,
                    'content' => $chunk->content,
                    'token_estimate' => $chunk->token_estimate,
                ])->values()
                : [],
        ];
    }

    private function formatUserKnowledgeDetail(AiHelperKnowledgeEntry $entry): array
    {
        $sourcePath = trim((string) ($entry->source_path ?? ''));
        $originalAvailable = $sourcePath !== ''
            && ! str_starts_with($sourcePath, 'seed:')
            && Storage::disk('local')->exists($sourcePath);
        $extractedContent = (string) ($entry->content ?? '');

        return [
            ...$this->formatKnowledgeEntry($entry),
            'extracted_content' => $extractedContent,
            'extracted_content_available' => trim($extractedContent) !== '',
            'original_available' => $originalAvailable,
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
