<?php

namespace App\Services\InspectionFireExtinguishers;

use App\Models\InspectionCheckRow;
use App\Models\InspectionFireExtinguisher;
use App\Models\InspectionFireExtinguisherIssue;
use App\Models\InspectionFireExtinguisherIssueEvent;
use App\Models\InspectionFireExtinguisherIssueOccurrence;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FireExtinguisherIssueSyncService
{
    /** @param Collection<int, InspectionCheckRow> $rows */
    public function syncRows(Collection $rows, ?int $actorUserId = null): void
    {
        $rows->filter(fn (InspectionCheckRow $row): bool => $row->inspection_type_key === 'fire-extinguisher-inspection'
            && (int) $row->equipment_catalog_id > 0
        )->each(fn (InspectionCheckRow $row) => $this->syncRow($row, $actorUserId));
    }

    public function syncReport(int $reportId, ?int $actorUserId = null): void
    {
        $this->syncRows(
            InspectionCheckRow::query()->where('report_id', $reportId)->get(),
            $actorUserId,
        );
    }

    private function syncRow(InspectionCheckRow $row, ?int $actorUserId): void
    {
        DB::transaction(function () use ($row, $actorUserId): void {
            // Serialize issue creation with lifecycle transitions and other report syncs
            // for this asset. This prevents duplicate active-key insert races.
            $asset = InspectionFireExtinguisher::query()
                ->lockForUpdate()
                ->find($row->equipment_catalog_id);
            if (! $asset || $asset->lifecycle_status === 'retired' || ! $asset->is_active) {
                return;
            }

            $activeKey = $this->activeKey((int) $asset->id, (string) $row->check_key);
            $issue = InspectionFireExtinguisherIssue::query()
                ->where('active_key', $activeKey)
                ->lockForUpdate()
                ->first();

            if (! $row->has_defect) {
                if ($issue && ! $this->hasReportEvent($issue->id, 'condition_now_good', $row->report_id)) {
                    $this->event($issue, 'condition_now_good', $actorUserId, $issue->status, $issue->status,
                        'A subsequent inspection recorded this criterion as good.', $row);
                }

                return;
            }

            if (! $issue) {
                $detectedAt = $row->submitted_at ?: now();
                $issue = InspectionFireExtinguisherIssue::query()->create([
                    'public_id' => (string) Str::uuid(),
                    'fire_extinguisher_id' => $asset->id,
                    'check_key' => $row->check_key,
                    'check_name' => $row->check_name,
                    'status' => 'open',
                    'severity' => 'medium',
                    'title' => trim(($asset->id_loc_no ?: $asset->barcode_no ?: 'Fire extinguisher').' - '.$row->check_name),
                    'description' => $row->remarks,
                    'first_detected_at' => $detectedAt,
                    'last_detected_at' => $detectedAt,
                    'active_key' => $activeKey,
                ]);
                $this->event($issue, 'opened', $actorUserId, null, 'open', $row->remarks, $row);
            } elseif ($issue->status === 'pending_verification') {
                $from = $issue->status;
                $issue->update([
                    'status' => 'in_progress',
                    'resolved_at' => null,
                    'resolved_by_user_id' => null,
                    'lock_version' => $issue->lock_version + 1,
                ]);
                $this->event($issue, 'defect_recurred', $actorUserId, $from, 'in_progress', $row->remarks, $row);
            }

            InspectionFireExtinguisherIssueOccurrence::query()->updateOrCreate(
                ['issue_id' => $issue->id, 'report_id' => $row->report_id],
                [
                    'inspection_check_row_id' => $row->id,
                    'check_value' => $row->check_value,
                    'remarks' => $row->remarks,
                    'evidence_count' => (int) $row->evidence_count,
                    'detected_at' => $row->submitted_at ?: now(),
                ],
            );

            $issue->update([
                'last_detected_at' => $row->submitted_at ?: now(),
                'description' => $row->remarks ?: $issue->description,
                'lock_version' => $issue->lock_version + 1,
            ]);

            if (! $this->hasReportEvent($issue->id, 'defect_detected', $row->report_id)) {
                $this->event($issue, 'defect_detected', $actorUserId, $issue->status, $issue->status, $row->remarks, $row);
            }
        });
    }

    private function activeKey(int $assetId, string $checkKey): string
    {
        return "fire-extinguisher:{$assetId}:".Str::of($checkKey)->lower()->trim();
    }

    private function hasReportEvent(int $issueId, string $eventType, int $reportId): bool
    {
        return InspectionFireExtinguisherIssueEvent::query()
            ->where('issue_id', $issueId)
            ->where('event_type', $eventType)
            ->where('metadata->report_id', $reportId)
            ->exists();
    }

    private function event(
        InspectionFireExtinguisherIssue $issue,
        string $type,
        ?int $actorUserId,
        ?string $from,
        ?string $to,
        ?string $note,
        InspectionCheckRow $row,
    ): void {
        InspectionFireExtinguisherIssueEvent::query()->create([
            'issue_id' => $issue->id,
            'event_type' => $type,
            'actor_user_id' => $actorUserId,
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note,
            'metadata' => [
                'report_id' => $row->report_id,
                'report_uid' => $row->report_uid,
                'display_id' => $row->display_id,
                'inspection_check_row_id' => $row->id,
            ],
        ]);
    }
}
