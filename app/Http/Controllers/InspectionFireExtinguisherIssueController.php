<?php

namespace App\Http\Controllers;

use App\Models\InspectionFireExtinguisherIssue;
use App\Services\AssignmentAuthorizationService;
use App\Services\AuditLogger;
use App\Services\InspectionFireExtinguishers\FireExtinguisherIssueWorkflowService;
use App\Services\ReportMediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class InspectionFireExtinguisherIssueController extends Controller
{
    public function __construct(
        private readonly AssignmentAuthorizationService $authorization,
        private readonly FireExtinguisherIssueWorkflowService $workflow,
        private readonly ReportMediaService $reportMedia,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->ensureCanView($request);
        $data = $request->validate([
            'status' => ['nullable', 'string', 'max:32'],
            'severity' => ['nullable', 'string', 'max:24'],
            'assigneeId' => ['nullable', 'integer'],
            'extinguisherId' => ['nullable', 'integer'],
            'overdue' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:190'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = InspectionFireExtinguisherIssue::query()
            ->with(['extinguisher', 'assignee', 'events.actor', 'resolutionMediaLinks.media'])
            ->withCount('occurrences');
        if ($status = trim((string) ($data['status'] ?? ''))) {
            $status === 'active'
                ? $query->whereIn('status', InspectionFireExtinguisherIssue::ACTIVE_STATUSES)
                : $query->where('status', $status);
        }
        if ($severity = trim((string) ($data['severity'] ?? ''))) {
            $query->where('severity', $severity);
        }
        if ($assigneeId = (int) ($data['assigneeId'] ?? 0)) {
            $query->where('assigned_to_user_id', $assigneeId);
        }
        if ($extinguisherId = (int) ($data['extinguisherId'] ?? 0)) {
            $query->where('fire_extinguisher_id', $extinguisherId);
        }
        if (($data['overdue'] ?? false) === true) {
            $query->whereIn('status', InspectionFireExtinguisherIssue::ACTIVE_STATUSES)->where('due_at', '<', now());
        }
        if ($search = trim((string) ($data['search'] ?? ''))) {
            $query->where(function ($builder) use ($search): void {
                $like = "%{$search}%";
                $builder->where('title', 'like', $like)->orWhere('description', 'like', $like)
                    ->orWhereHas('extinguisher', fn ($asset) => $asset->where('id_loc_no', 'like', $like)->orWhere('barcode_no', 'like', $like));
            });
        }
        $page = $query->orderByRaw("CASE WHEN status IN ('open','in_progress','pending_verification') THEN 0 ELSE 1 END")
            ->orderBy('due_at')->orderByDesc('last_detected_at')->paginate((int) ($data['perPage'] ?? 25));

        return response()->json([
            'data' => collect($page->items())->map(fn ($issue) => $this->format($issue))->values(),
            'meta' => ['page' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Request $request, InspectionFireExtinguisherIssue $issue): JsonResponse
    {
        $this->ensureCanView($request);

        return response()->json(['data' => $this->format($issue->load([
            'extinguisher', 'assignee', 'occurrences.issue', 'events.actor', 'resolutionMediaLinks.media',
        ]))]);
    }

    public function update(Request $request, InspectionFireExtinguisherIssue $issue): JsonResponse
    {
        $this->ensureCanManage($request);
        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'severity' => ['sometimes', 'in:low,medium,high,critical'],
            'dueAt' => ['nullable', 'date'],
            'lockVersion' => ['required', 'integer', 'min:1'],
        ]);
        $this->assertClientLock($issue, (int) $data['lockVersion']);
        $before = $issue->only(['title', 'description', 'severity', 'due_at']);
        $updated = $this->workflow->updateMetadata($issue, (int) $request->user()->id, [
            ...array_key_exists('title', $data) ? ['title' => $data['title']] : [],
            ...array_key_exists('description', $data) ? ['description' => $data['description']] : [],
            ...array_key_exists('severity', $data) ? ['severity' => $data['severity']] : [],
            ...array_key_exists('dueAt', $data) ? ['due_at' => $data['dueAt']] : [],
        ]);
        AuditLogger::log($request, 'fire_extinguisher_issue_updated', null, ['issue_id' => $updated->id, 'before' => $before, 'after' => $updated->only(array_keys($before))]);

        return response()->json(['data' => $this->format($updated)]);
    }

    public function assign(Request $request, InspectionFireExtinguisherIssue $issue): JsonResponse
    {
        $this->ensureCanManage($request);
        $data = $request->validate([
            'assignedToUserId' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query
                    ->whereNull('deleted_at')
                    ->whereRaw('LOWER(status) = ?', ['active'])),
            ],
            'note' => ['nullable', 'string', 'max:5000'],
            'lockVersion' => ['required', 'integer'],
        ]);
        $this->assertClientLock($issue, (int) $data['lockVersion']);

        return $this->actionResponse($request, 'assigned', $this->workflow->assign($issue, (int) $data['assignedToUserId'], (int) $request->user()->id, $data['note'] ?? null));
    }

    public function start(Request $request, InspectionFireExtinguisherIssue $issue): JsonResponse
    {
        $this->ensureCanManage($request);
        $this->validateLock($request, $issue);

        return $this->actionResponse($request, 'started', $this->workflow->start($issue, (int) $request->user()->id, $request->input('note')));
    }

    public function resolve(Request $request, InspectionFireExtinguisherIssue $issue): JsonResponse
    {
        $this->ensureCanManage($request);
        $data = $request->validate([
            'correctiveAction' => ['required', 'string', 'max:10000'],
            'resolutionNotes' => ['required', 'string', 'max:10000'],
            'resolutionPhotos' => ['sometimes', 'array', 'max:10'],
            'resolutionPhotos.*.mediaId' => ['required_with:resolutionPhotos', 'string', 'max:64'],
            'lockVersion' => ['required', 'integer'],
        ]);
        $this->assertClientLock($issue, (int) $data['lockVersion']);
        $updated = DB::transaction(function () use ($data, $issue, $request): InspectionFireExtinguisherIssue {
            if (array_key_exists('resolutionPhotos', $data)) {
                $this->reportMedia->syncPayloadLinks(
                    ['photos' => $data['resolutionPhotos']],
                    'fire_extinguisher_issue_resolution',
                    $issue->public_id,
                    (int) $request->user()->id,
                    'inspection',
                );
            }

            return $this->workflow->resolve($issue, (int) $request->user()->id, $data['correctiveAction'], $data['resolutionNotes']);
        });

        return $this->actionResponse($request, 'resolved', $updated);
    }

    public function verify(Request $request, InspectionFireExtinguisherIssue $issue): JsonResponse
    {
        $this->ensureCanVerify($request);
        $data = $request->validate(['note' => ['required', 'string', 'max:10000'], 'lockVersion' => ['required', 'integer']]);
        $this->assertClientLock($issue, (int) $data['lockVersion']);

        return $this->actionResponse($request, 'verified', $this->workflow->verify($issue, (int) $request->user()->id, $data['note']));
    }

    public function reopen(Request $request, InspectionFireExtinguisherIssue $issue): JsonResponse
    {
        $this->ensureCanManage($request);
        $data = $request->validate(['note' => ['required', 'string', 'max:10000'], 'lockVersion' => ['required', 'integer']]);
        $this->assertClientLock($issue, (int) $data['lockVersion']);

        return $this->actionResponse($request, 'reopened', $this->workflow->reopen($issue, (int) $request->user()->id, $data['note']));
    }

    public function cancel(Request $request, InspectionFireExtinguisherIssue $issue): JsonResponse
    {
        $this->ensureCanManage($request);
        $data = $request->validate(['note' => ['required', 'string', 'max:10000'], 'lockVersion' => ['required', 'integer']]);
        $this->assertClientLock($issue, (int) $data['lockVersion']);

        return $this->actionResponse($request, 'cancelled', $this->workflow->cancel($issue, (int) $request->user()->id, $data['note']));
    }

    private function actionResponse(Request $request, string $action, InspectionFireExtinguisherIssue $issue): JsonResponse
    {
        AuditLogger::log($request, "fire_extinguisher_issue_{$action}", null, ['issue_id' => $issue->id, 'status' => $issue->status]);

        return response()->json(['data' => $this->format($issue)]);
    }

    private function validateLock(Request $request, InspectionFireExtinguisherIssue $issue): void
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:10000'], 'lockVersion' => ['required', 'integer']]);
        $this->assertClientLock($issue, (int) $data['lockVersion']);
    }

    private function assertClientLock(InspectionFireExtinguisherIssue $issue, int $lockVersion): void
    {
        if ($lockVersion !== (int) $issue->lock_version) {
            abort(409, 'The issue was updated by another user.');
        }
    }

    private function format(InspectionFireExtinguisherIssue $issue): array
    {
        $issue->loadMissing(['extinguisher', 'assignee', 'resolutionMediaLinks.media']);

        return [
            'id' => $issue->id, 'publicId' => $issue->public_id, 'fireExtinguisherId' => $issue->fire_extinguisher_id,
            'asset' => $issue->extinguisher ? ['idLocNo' => $issue->extinguisher->id_loc_no, 'barcodeNo' => $issue->extinguisher->barcode_no, 'zone' => $issue->extinguisher->zone, 'location' => $issue->extinguisher->main_location_name, 'subLocation' => $issue->extinguisher->sub_location_name] : null,
            'checkKey' => $issue->check_key, 'checkName' => $issue->check_name, 'status' => $issue->status,
            'severity' => $issue->severity, 'title' => $issue->title, 'description' => $issue->description,
            'assignee' => $issue->assignee ? ['id' => $issue->assignee->id, 'name' => $issue->assignee->name] : null,
            'dueAt' => $issue->due_at?->toIso8601String(), 'isOverdue' => $issue->due_at?->isPast() && in_array($issue->status, InspectionFireExtinguisherIssue::ACTIVE_STATUSES, true),
            'firstDetectedAt' => $issue->first_detected_at?->toIso8601String(), 'lastDetectedAt' => $issue->last_detected_at?->toIso8601String(),
            'correctiveAction' => $issue->corrective_action, 'resolutionNotes' => $issue->resolution_notes,
            'resolutionEvidence' => $issue->resolutionMediaLinks->map(fn ($link) => $link->media ? [
                'mediaId' => $link->media->public_id,
                'fileName' => $link->media->original_name,
                'mimeType' => $link->media->mime_type,
                'sizeBytes' => $link->media->size_bytes,
                'url' => '/report-media/'.$link->media->public_id,
                'thumbnailUrl' => $link->media->thumbnail_path ? '/report-media/'.$link->media->public_id.'?variant=thumbnail' : null,
            ] : null)->filter()->values(),
            'resolvedAt' => $issue->resolved_at?->toIso8601String(), 'verifiedAt' => $issue->verified_at?->toIso8601String(), 'closedAt' => $issue->closed_at?->toIso8601String(),
            'occurrenceCount' => $issue->occurrences_count ?? $issue->occurrences?->count() ?? 0,
            'occurrences' => $issue->relationLoaded('occurrences') ? $issue->occurrences->map(fn ($row) => ['id' => $row->id, 'reportId' => $row->report_id, 'checkValue' => $row->check_value, 'remarks' => $row->remarks, 'evidenceCount' => $row->evidence_count, 'detectedAt' => $row->detected_at?->toIso8601String()])->values() : null,
            'events' => $issue->relationLoaded('events') ? $issue->events->sortByDesc('id')->map(fn ($event) => ['id' => $event->id, 'type' => $event->event_type, 'actor' => $event->actor?->name, 'fromStatus' => $event->from_status, 'toStatus' => $event->to_status, 'note' => $event->note, 'metadata' => $event->metadata, 'createdAt' => $event->created_at?->toIso8601String()])->values() : null,
            'lockVersion' => $issue->lock_version,
        ];
    }

    private function ensureCanView(Request $request): void
    {
        $this->ensure($request, 'reports.manage|reports.inspection.view');
    }

    private function ensureCanManage(Request $request): void
    {
        $this->ensure($request, 'reports.manage|reports.inspection.issues.manage');
    }

    private function ensureCanVerify(Request $request): void
    {
        $this->ensure($request, 'reports.manage|reports.inspection.issues.verify');
    }

    private function ensure(Request $request, string $permissions): void
    {
        if (! $request->user() || ! $this->authorization->hasPermission($request->user(), $permissions)) {
            abort(403, 'Missing fire extinguisher issue permission.');
        }
    }
}
