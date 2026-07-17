<?php

namespace App\Console\Commands;

use App\Models\Report;
use App\Services\WorkflowNotificationService;
use App\Services\WorkflowRbacAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReassignWorkflowRole extends Command
{
    protected $signature = 'workflow:reassign-role
        {module : leave, overtime, payroll, or report}
        {toRole : Valid replacement role}
        {--from= : Existing role to replace; omit to repair ownerless records}
        {--reason=Approved workflow ownership repair. : Audit reason}
        {--actor=System Maintenance : Operator name recorded in history}
        {--apply : Apply changes; otherwise perform a dry run}';

    protected $description = 'Safely reassign pending workflow action ownership with an append-only audit event';

    public function handle(
        WorkflowRbacAuditService $audit,
        WorkflowNotificationService $notifications,
    ): int {
        $module = strtolower(trim((string) $this->argument('module')));
        $toRole = trim((string) $this->argument('toRole'));
        $fromRole = trim((string) $this->option('from')) ?: null;
        $reason = trim((string) $this->option('reason'));
        $actorName = trim((string) $this->option('actor')) ?: 'System Maintenance';

        try {
            $audit->assertRoleCanOwnModule($module, $toRole);
            $query = $audit->reassignableQuery($module, $fromRole);
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        $count = (clone $query)->count();
        if (! $this->option('apply')) {
            $this->info("Dry run: {$count} {$module} workflow record(s) would be reassigned to {$toRole}.");

            return self::SUCCESS;
        }
        if ($reason === '') {
            $this->error('A non-empty --reason is required when applying a reassignment.');

            return self::INVALID;
        }

        $updated = 0;
        $query->orderBy('id')->chunkById(100, function ($records) use (
            $audit,
            $notifications,
            $module,
            $toRole,
            $fromRole,
            $reason,
            $actorName,
            &$updated,
        ): void {
            foreach ($records as $record) {
                $fresh = DB::transaction(function () use ($record, $toRole, $fromRole, $reason, $actorName) {
                    $locked = $record->newQuery()->lockForUpdate()->findOrFail($record->getKey());
                    $currentRole = trim((string) ($locked->next_action_role ?? ''));
                    if (($fromRole === null && $currentRole !== '')
                        || ($fromRole !== null && $currentRole !== $fromRole)) {
                        return null;
                    }
                    $history = collect(is_array($locked->approval_history) ? $locked->approval_history : [])
                        ->push([
                            'id' => (string) Str::uuid(),
                            'at' => now()->toIso8601String(),
                            'action' => 'Workflow Reassigned',
                            'by' => $actorName,
                            'byUserId' => '',
                            'remarks' => $reason,
                            'previousRole' => $currentRole !== '' ? $currentRole : null,
                            'newRole' => $toRole,
                        ])
                        ->take(-30)
                        ->values()
                        ->all();
                    $updates = [
                        'next_action_role' => $toRole,
                        'approval_history' => $history,
                    ];
                    if (array_key_exists('version', $locked->getAttributes())) {
                        $updates['version'] = ((int) $locked->version) + 1;
                    }
                    $locked->update($updates);

                    return $locked->fresh();
                });
                if (! $fresh) {
                    continue;
                }
                $updated++;
                $ownerId = $audit->ownerUserId($fresh);
                $recordType = $fresh instanceof Report ? 'report' : $module;
                $displayId = $fresh->display_id ?? $fresh->report_uid ?? (string) $fresh->getKey();
                $notifications->emit(
                    module: $module,
                    eventType: 'workflow_reassigned',
                    recordType: $recordType,
                    recordId: $fresh->getKey(),
                    recordDisplayId: (string) $displayId,
                    ownerUserId: (int) $ownerId,
                    actor: ['userId' => null, 'name' => $actorName, 'email' => null],
                    targetRoles: [$toRole],
                    targetUserIds: $ownerId ? [$ownerId] : [],
                    actionRequired: true,
                    remarks: $reason,
                    metadata: [
                        'workflowStage' => $fresh->workflow_stage,
                        'nextActionRole' => $toRole,
                        'previousRole' => $fromRole,
                    ],
                );
            }
        });

        $this->info("Reassigned {$updated} {$module} workflow record(s) to {$toRole}.");

        return self::SUCCESS;
    }
}
