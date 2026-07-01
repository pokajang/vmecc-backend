<?php

namespace App\Http\Controllers;

use App\Http\Requests\Feedback\ListFeedbackReportsRequest;
use App\Http\Requests\Feedback\StoreFeedbackReportRequest;
use App\Http\Requests\Feedback\UpdateFeedbackReportRequest;
use App\Models\FeedbackReport;
use App\Models\User;
use App\Notifications\FeedbackReportSubmittedNotification;
use App\Services\AssignmentAuthorizationService;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

class FeedbackReportController extends Controller
{
    public function __construct(
        private readonly AssignmentAuthorizationService $authorizationService,
    ) {
    }

    public function store(StoreFeedbackReportRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $actor = $request->user();

            $report = FeedbackReport::create([
                'reporter_user_id' => $actor->id,
                'message' => (string) $validated['message'],
                'status' => FeedbackReport::STATUS_NEW,
                'page_context' => $validated['page_context'] ?? [],
                'reporter_ip' => Str::limit((string) $request->ip(), 64, ''),
                'reporter_user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
            ]);

            AuditLogger::log($request, 'feedback_report_created', $actor, [
                'feedback_report_id' => $report->id,
                'status' => $report->status,
            ]);

            $this->notifySystemAdministrators($report->fresh(['reporter']));

            return response()->json([
                'message' => 'Feedback report submitted.',
                'data' => $this->formatSummary($report->fresh(['reporter', 'reviewer'])),
            ], 201);
        } catch (Throwable $e) {
            Log::error('Feedback report submission failed.', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Unable to submit feedback report.',
                'code' => 'FEEDBACK_REPORT_CREATE_FAILED',
            ], 500);
        }
    }

    public function index(ListFeedbackReportsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $status = strtolower((string) ($validated['status'] ?? FeedbackReport::STATUS_NEW));
            $perPage = min(50, max(1, (int) ($validated['per_page'] ?? 20)));

            $query = FeedbackReport::query()
                ->with(['reporter', 'reviewer'])
                ->latest('created_at');

            if ($status !== '' && $status !== 'all' && in_array($status, FeedbackReport::STATUSES, true)) {
                $query->where('status', $status);
            }

            $page = $query->paginate($perPage);
            $countsByStatus = FeedbackReport::query()
                ->selectRaw('status, count(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status')
                ->all();

            return response()->json([
                'data' => collect($page->items())
                    ->map(fn (FeedbackReport $report) => $this->formatSummary($report))
                    ->values(),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                    'counts' => [
                        'new' => (int) ($countsByStatus[FeedbackReport::STATUS_NEW] ?? 0),
                        'reviewing' => (int) ($countsByStatus[FeedbackReport::STATUS_REVIEWING] ?? 0),
                        'resolved' => (int) ($countsByStatus[FeedbackReport::STATUS_RESOLVED] ?? 0),
                        'dismissed' => (int) ($countsByStatus[FeedbackReport::STATUS_DISMISSED] ?? 0),
                        'all' => array_sum(array_map('intval', $countsByStatus)),
                    ],
                ],
            ]);
        } catch (Throwable $e) {
            Log::error('Feedback report listing failed.', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Unable to load feedback reports.',
                'code' => 'FEEDBACK_REPORT_LIST_FAILED',
            ], 500);
        }
    }

    public function show(Request $request, int $reportId): JsonResponse
    {
        try {
            $report = FeedbackReport::query()
                ->with(['reporter', 'reviewer'])
                ->find($reportId);

            if (! $report) {
                return response()->json([
                    'message' => 'Feedback report not found.',
                    'code' => 'FEEDBACK_REPORT_NOT_FOUND',
                ], 404);
            }

            return response()->json([
                'data' => $this->formatDetail($report),
            ]);
        } catch (Throwable $e) {
            Log::error('Feedback report detail failed.', [
                'user_id' => $request->user()?->id,
                'report_id' => $reportId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Unable to load feedback report.',
                'code' => 'FEEDBACK_REPORT_DETAIL_FAILED',
            ], 500);
        }
    }

    public function update(UpdateFeedbackReportRequest $request, int $reportId): JsonResponse
    {
        $validated = $request->validated();

        try {
            $report = FeedbackReport::query()->find($reportId);

            if (! $report) {
                return response()->json([
                    'message' => 'Feedback report not found.',
                    'code' => 'FEEDBACK_REPORT_NOT_FOUND',
                ], 404);
            }

            $status = (string) $validated['status'];
            $report->forceFill([
                'status' => $status,
                'admin_note' => trim((string) ($validated['admin_note'] ?? '')) ?: null,
                'reviewed_by' => $status === FeedbackReport::STATUS_NEW ? null : $request->user()->id,
                'reviewed_at' => $status === FeedbackReport::STATUS_NEW ? null : now(),
            ])->save();

            AuditLogger::log($request, 'feedback_report_updated', $request->user(), [
                'feedback_report_id' => $report->id,
                'status' => $status,
            ]);

            return response()->json([
                'message' => 'Feedback report updated.',
                'data' => $this->formatDetail($report->fresh(['reporter', 'reviewer'])),
            ]);
        } catch (Throwable $e) {
            Log::error('Feedback report update failed.', [
                'user_id' => $request->user()?->id,
                'report_id' => $reportId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Unable to update feedback report.',
                'code' => 'FEEDBACK_REPORT_UPDATE_FAILED',
            ], 500);
        }
    }

    private function notifySystemAdministrators(FeedbackReport $report): void
    {
        try {
            $recipients = User::query()
                ->where('status', 'active')
                ->whereNotNull('email')
                ->where('email', '!=', '')
                ->with(['roleAssignments.role'])
                ->get()
                ->filter(function (User $user) {
                    $roles = $this->authorizationService->getActiveRoleNames($user)
                        ->map(fn (string $role) => strtolower(trim($role)))
                        ->values()
                        ->all();

                    return in_array('system administrator', $roles, true)
                        || in_array('system admin', $roles, true);
                })
                ->unique(fn (User $user) => strtolower(trim((string) $user->email)))
                ->values();

            if ($recipients->isEmpty()) {
                return;
            }

            $frontendBase = rtrim((string) config('app.frontend_url', config('app.url', '')), '/');
            $adminUrl = $frontendBase !== '' ? "{$frontendBase}/admin/feedback-reports" : '/admin/feedback-reports';

            Notification::send($recipients, new FeedbackReportSubmittedNotification($report, $adminUrl));
        } catch (Throwable $e) {
            Log::warning('Feedback report sysadmin notification failed.', [
                'feedback_report_id' => $report->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function formatSummary(FeedbackReport $report): array
    {
        $pageContext = is_array($report->page_context) ? $report->page_context : [];

        return [
            'id' => $report->id,
            'message' => (string) $report->message,
            'status' => $report->status,
            'reporter' => $this->formatUser($report->reporter),
            'page' => [
                'title' => $pageContext['title'] ?? null,
                'path' => $pageContext['path'] ?? null,
                'search' => $pageContext['search'] ?? null,
            ],
            'created_at' => optional($report->created_at)->toIso8601String(),
            'updated_at' => optional($report->updated_at)->toIso8601String(),
            'reviewed_at' => optional($report->reviewed_at)->toIso8601String(),
            'reviewer' => $this->formatUser($report->reviewer),
        ];
    }

    private function formatDetail(FeedbackReport $report): array
    {
        return [
            ...$this->formatSummary($report),
            'page_context' => $report->page_context ?: [],
            'reporter_ip' => $report->reporter_ip,
            'reporter_user_agent' => $report->reporter_user_agent,
            'admin_note' => (string) ($report->admin_note ?? ''),
        ];
    }

    private function formatUser(?User $user): ?array
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
