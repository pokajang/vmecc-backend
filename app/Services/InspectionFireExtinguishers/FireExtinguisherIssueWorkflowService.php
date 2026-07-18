<?php

namespace App\Services\InspectionFireExtinguishers;

use App\Models\InspectionFireExtinguisher;
use App\Models\InspectionFireExtinguisherIssue;
use App\Models\InspectionFireExtinguisherIssueEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FireExtinguisherIssueWorkflowService
{
    /** @param array<string, mixed> $attributes */
    public function updateMetadata(InspectionFireExtinguisherIssue $issue, int $actorId, array $attributes): InspectionFireExtinguisherIssue
    {
        return $this->mutate($issue, $actorId, function (InspectionFireExtinguisherIssue $locked) use ($attributes): array {
            $locked->fill($attributes);

            return ['updated', $locked->status, $locked->status, null, ['fields' => array_keys($attributes)]];
        });
    }

    public function assign(InspectionFireExtinguisherIssue $issue, int $assigneeId, int $actorId, ?string $note = null): InspectionFireExtinguisherIssue
    {
        return $this->mutate($issue, $actorId, function (InspectionFireExtinguisherIssue $locked) use ($assigneeId, $note): array {
            if (! in_array($locked->status, InspectionFireExtinguisherIssue::ACTIVE_STATUSES, true)) {
                throw ValidationException::withMessages(['status' => ['Only an active issue can be assigned.']]);
            }
            $locked->assigned_to_user_id = $assigneeId;

            return ['assigned', $locked->status, $locked->status, $note, ['assigned_to_user_id' => $assigneeId]];
        });
    }

    public function unassign(InspectionFireExtinguisherIssue $issue, int $actorId, ?string $note = null): InspectionFireExtinguisherIssue
    {
        return $this->mutate($issue, $actorId, function (InspectionFireExtinguisherIssue $locked) use ($note): array {
            if (! in_array($locked->status, InspectionFireExtinguisherIssue::ACTIVE_STATUSES, true)) {
                throw ValidationException::withMessages(['status' => ['Only an active issue can be unassigned.']]);
            }
            if (! $locked->assigned_to_user_id) {
                throw ValidationException::withMessages(['assignedToUserId' => ['The issue is already unassigned.']]);
            }
            $previousAssigneeId = (int) $locked->assigned_to_user_id;
            $from = $locked->status;
            $locked->assigned_to_user_id = null;
            if ($locked->status === 'in_progress') {
                $locked->status = 'open';
            }

            return ['unassigned', $from, $locked->status, $note, ['previous_assigned_to_user_id' => $previousAssigneeId]];
        });
    }

    public function start(InspectionFireExtinguisherIssue $issue, int $actorId, ?string $note = null): InspectionFireExtinguisherIssue
    {
        return $this->transition($issue, $actorId, ['open'], 'in_progress', 'started', $note, function ($locked): void {
            if (! $locked->assigned_to_user_id) {
                throw ValidationException::withMessages(['assignedToUserId' => ['Assign the issue before starting work.']]);
            }
        });
    }

    public function resolve(InspectionFireExtinguisherIssue $issue, int $actorId, string $action, string $notes): InspectionFireExtinguisherIssue
    {
        return $this->transition($issue, $actorId, ['open', 'in_progress'], 'pending_verification', 'resolved', $notes,
            function ($locked) use ($actorId, $action, $notes): void {
                if (trim($action) === '' || trim($notes) === '') {
                    throw ValidationException::withMessages(['resolution' => ['Corrective action and resolution notes are required.']]);
                }
                $locked->corrective_action = trim($action);
                $locked->resolution_notes = trim($notes);
                $locked->resolved_at = now();
                $locked->resolved_by_user_id = $actorId;
            });
    }

    public function verify(InspectionFireExtinguisherIssue $issue, int $actorId, string $note): InspectionFireExtinguisherIssue
    {
        return $this->transition($issue, $actorId, ['pending_verification'], 'closed', 'verified', $note,
            function ($locked) use ($actorId, $note): void {
                if (trim($note) === '') {
                    throw ValidationException::withMessages(['note' => ['Verification notes are required.']]);
                }
                if ((int) $locked->resolved_by_user_id === $actorId) {
                    throw ValidationException::withMessages([
                        'verifier' => ['The resolver cannot verify their own corrective work.'],
                    ]);
                }
                $locked->verified_at = now();
                $locked->verified_by_user_id = $actorId;
                $locked->closed_at = now();
                $locked->closed_by_user_id = $actorId;
                $locked->active_key = null;
            });
    }

    public function reopen(InspectionFireExtinguisherIssue $issue, int $actorId, string $note): InspectionFireExtinguisherIssue
    {
        return $this->transition($issue, $actorId, ['closed', 'cancelled'], 'open', 'reopened', $note,
            function ($locked) use ($note): void {
                if (trim($note) === '') {
                    throw ValidationException::withMessages(['note' => ['A reopen reason is required.']]);
                }
                $asset = InspectionFireExtinguisher::query()->findOrFail($locked->fire_extinguisher_id);
                if (! $asset->is_active || $asset->lifecycle_status === 'retired') {
                    throw ValidationException::withMessages([
                        'status' => ['An issue cannot be reopened for a retired extinguisher. Restore the asset first.'],
                    ]);
                }
                $activeKey = 'fire-extinguisher:'.$locked->fire_extinguisher_id.':'.strtolower(trim($locked->check_key));
                if (InspectionFireExtinguisherIssue::query()->where('active_key', $activeKey)->whereKeyNot($locked->id)->exists()) {
                    throw ValidationException::withMessages([
                        'status' => ['This criterion already has another active issue.'],
                    ]);
                }
                $locked->active_key = $activeKey;
                $locked->resolved_at = null;
                $locked->resolved_by_user_id = null;
                $locked->verified_at = null;
                $locked->verified_by_user_id = null;
                $locked->closed_at = null;
                $locked->closed_by_user_id = null;
            });
    }

    public function cancel(InspectionFireExtinguisherIssue $issue, ?int $actorId, string $note): InspectionFireExtinguisherIssue
    {
        return $this->transition($issue, $actorId, InspectionFireExtinguisherIssue::ACTIVE_STATUSES, 'cancelled', 'cancelled', $note,
            function ($locked) use ($actorId, $note): void {
                if (trim($note) === '') {
                    throw ValidationException::withMessages(['note' => ['A cancellation reason is required.']]);
                }
                $locked->closed_at = now();
                $locked->closed_by_user_id = $actorId;
                $locked->active_key = null;
            });
    }

    public function closeForRetirement(InspectionFireExtinguisherIssue $issue, ?int $actorId, string $reason): void
    {
        if (in_array($issue->status, InspectionFireExtinguisherIssue::ACTIVE_STATUSES, true)) {
            $this->cancel($issue, $actorId, 'Asset retired: '.$reason);
        }
    }

    private function transition($issue, ?int $actorId, array $allowed, string $to, string $event, ?string $note, ?callable $before = null): InspectionFireExtinguisherIssue
    {
        return $this->mutate($issue, $actorId, function ($locked) use ($allowed, $to, $event, $note, $before): array {
            if (! in_array($locked->status, $allowed, true)) {
                throw ValidationException::withMessages(['status' => ["Issue cannot move from {$locked->status} to {$to}."]]);
            }
            $from = $locked->status;
            $before?->__invoke($locked);
            $locked->status = $to;

            return [$event, $from, $to, $note, null];
        });
    }

    private function mutate(InspectionFireExtinguisherIssue $issue, ?int $actorId, callable $callback): InspectionFireExtinguisherIssue
    {
        return DB::transaction(function () use ($issue, $actorId, $callback): InspectionFireExtinguisherIssue {
            // All issue mutations lock the parent asset first. Retirement and
            // inspection sync use the same lock order, avoiding deadlocks and
            // serializing reopen/create decisions for one extinguisher.
            InspectionFireExtinguisher::query()
                ->lockForUpdate()
                ->findOrFail($issue->fire_extinguisher_id);
            $locked = InspectionFireExtinguisherIssue::query()->lockForUpdate()->findOrFail($issue->id);
            if ((int) $issue->lock_version !== (int) $locked->lock_version) {
                abort(409, 'The issue was updated by another user. Refresh and try again.');
            }
            [$event, $from, $to, $note, $metadata] = $callback($locked);
            $locked->lock_version++;
            $locked->save();
            InspectionFireExtinguisherIssueEvent::query()->create([
                'issue_id' => $locked->id,
                'event_type' => $event,
                'actor_user_id' => $actorId,
                'from_status' => $from,
                'to_status' => $to,
                'note' => $note,
                'metadata' => $metadata,
            ]);

            return $locked->fresh(['assignee', 'occurrences', 'events.actor']);
        });
    }
}
