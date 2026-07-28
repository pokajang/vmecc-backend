<?php

namespace App\Console\Commands;

use App\Services\WorkflowRoutingAuditService;
use Illuminate\Console\Command;

class AuditWorkflowRouting extends Command
{
    protected $signature = 'workflow:audit-routing {--json : Emit machine-readable JSON}';

    protected $description = 'Audit team identity, duty coverage, pending workflow recipients, and salary payment readiness';

    public function handle(WorkflowRoutingAuditService $audit): int
    {
        $issues = $audit->issues();
        if ($this->option('json')) {
            $this->line($issues->toJson(JSON_PRETTY_PRINT));
        } elseif ($issues->isEmpty()) {
            $this->info('No workflow routing integrity issues found.');
        } else {
            $this->table(
                ['Category', 'Record', 'Display', 'Team', 'Role', 'Reason'],
                $issues->map(fn (array $issue) => [
                    $issue['category'],
                    $issue['record_id'],
                    $issue['display_id'],
                    $issue['team'],
                    $issue['role'],
                    $issue['reason'],
                ])->all(),
            );
        }

        return $issues->isEmpty() ? self::SUCCESS : self::FAILURE;
    }
}
