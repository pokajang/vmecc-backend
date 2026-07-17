<?php

namespace App\Console\Commands;

use App\Services\WorkflowRbacAuditService;
use Illuminate\Console\Command;

class AuditWorkflowRbac extends Command
{
    protected $signature = 'workflow:audit-rbac {--json : Emit machine-readable JSON}';

    protected $description = 'Report pending workflow records with unreachable role-based action owners';

    public function handle(WorkflowRbacAuditService $audit): int
    {
        $issues = $audit->pendingOwnershipIssues();
        if ($this->option('json')) {
            $this->line($issues->values()->toJson(JSON_PRETTY_PRINT));
        } elseif ($issues->isEmpty()) {
            $this->info('No unreachable pending workflow owners found.');
        } else {
            $this->table(
                ['Module', 'Record', 'Status', 'Stage', 'Next role', 'Reason'],
                $issues->map(fn ($issue) => [
                    $issue['module'],
                    $issue['display_id'] ?: $issue['record_id'],
                    $issue['status'],
                    $issue['stage'],
                    $issue['next_action_role'],
                    $issue['reason'],
                ])->all(),
            );
        }

        return $issues->isEmpty() ? self::SUCCESS : self::FAILURE;
    }
}
