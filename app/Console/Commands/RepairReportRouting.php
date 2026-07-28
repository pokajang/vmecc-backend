<?php

namespace App\Console\Commands;

use App\Models\Report;
use App\Models\ReportRoutingEvent;
use App\Models\Team;
use App\Models\User;
use App\Services\AssignmentAuthorizationService;
use App\Services\RoleCatalog;
use App\Services\WorkflowNotificationService;
use App\Services\WorkflowRecipientResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class RepairReportRouting extends Command
{
    protected $signature = 'workflow:repair-report-routing
        {report : Numeric report ID}
        {--team= : Persisted workflow team ID}
        {--role= : Required workflow role; defaults to the current role}
        {--user= : Explicit eligible recipient user ID}
        {--actor-user= : Administrator user ID responsible for the repair}
        {--reason= : Required audit reason}
        {--apply : Apply the repair; otherwise show a dry run}';

    protected $description = 'Explicitly repair one stranded report without widening its team boundary';

    public function handle(
        AssignmentAuthorizationService $authorization,
        WorkflowRecipientResolver $recipients,
        WorkflowNotificationService $notifications,
    ): int {
        $report = Report::query()->find((int) $this->argument('report'));
        $team = Team::query()->find((int) $this->option('team'));
        $user = User::query()->find((int) $this->option('user'));
        $actor = User::query()->find((int) $this->option('actor-user'));
        $role = trim((string) ($this->option('role') ?: $report?->next_action_role));
        $reason = trim((string) $this->option('reason'));

        if (! $report || ! in_array($report->status, ['Submitted', 'Reviewed'], true)) {
            return $this->invalid('The report does not exist or no longer has an open workflow.');
        }
        if (! $team || ! $user || ! $actor || $role === '' || $reason === '') {
            return $this->invalid('--team, --user, --actor-user, and --reason are required.');
        }
        if ($actor->status !== null && strtolower(trim((string) $actor->status)) !== 'active') {
            return $this->invalid('The actor must be an active administrator with roles.assign.');
        }
        if (! Role::query()->where('guard_name', 'web')->where('name', $role)->exists()) {
            return $this->invalid("Role '{$role}' does not exist.");
        }
        if (! $authorization->isSystemAdministrator($actor)
            && ! $authorization->hasPermission($actor, 'roles.assign')) {
            return $this->invalid('The actor must be an active administrator with roles.assign.');
        }

        $recipient = $recipients
            ->resolveRole($role, RoleCatalog::isScopedRole($role) ? (int) $team->id : null, excludeUserId: (int) $report->owner_user_id)
            ->first(fn (array $row) => (int) $row['userId'] === (int) $user->id);
        if (! is_array($recipient)) {
            return $this->invalid("User {$user->id} is not an active {$role} recipient for {$team->name}.");
        }

        if (! $this->option('apply')) {
            $this->info(
                "Dry run: {$report->display_id} would route to {$user->name} as {$role} for {$team->name}.",
            );

            return self::SUCCESS;
        }

        $repairError = DB::transaction(function () use (
            $report,
            $team,
            $user,
            $actor,
            $role,
            $reason,
            $notifications,
            $recipients,
        ): ?string {
            $locked = Report::query()->lockForUpdate()->findOrFail($report->id);
            if (! in_array($locked->status, ['Submitted', 'Reviewed'], true)
                || ! in_array($locked->workflow_stage, ['review', 'approve'], true)) {
                return 'The report no longer has an open workflow.';
            }
            $currentRecipient = $recipients
                ->resolveRole(
                    $role,
                    RoleCatalog::isScopedRole($role) ? (int) $team->id : null,
                    excludeUserId: (int) $locked->owner_user_id,
                )
                ->first(fn (array $row) => (int) $row['userId'] === (int) $user->id);
            if (! is_array($currentRecipient)) {
                return "User {$user->id} is no longer an active {$role} recipient for {$team->name}.";
            }
            $previousUserId = $locked->next_action_user_id;
            $history = collect(is_array($locked->approval_history) ? $locked->approval_history : [])
                ->push([
                    'id' => (string) Str::uuid(),
                    'at' => now()->toIso8601String(),
                    'action' => 'Workflow Routing Repaired',
                    'by' => $actor->name,
                    'byUserId' => (string) $actor->id,
                    'remarks' => $reason,
                    'previousRole' => $locked->next_action_role,
                    'newRole' => $role,
                    'teamId' => (int) $team->id,
                    'recipientUserId' => (int) $user->id,
                ])
                ->take(-30)
                ->values()
                ->all();
            $routingReason = ($currentRecipient['source'] ?? '') === 'temporary_coverage'
                ? 'team_temporary_coverage'
                : ($locked->workflow_stage === 'approve'
                    ? 'approval_role_assignment'
                    : 'manual_routing_repair');

            $locked->update([
                'scope_team_id' => (int) $team->id,
                'next_action_role' => $role,
                'next_action_user_id' => (int) $user->id,
                'next_action_duty_coverage_assignment_id' => $currentRecipient['dutyCoverageAssignmentId'],
                'routing_reason_code' => $routingReason,
                'approval_history' => $history,
                'version' => ((int) $locked->version) + 1,
            ]);
            ReportRoutingEvent::query()->create([
                'report_id' => $locked->id,
                'event_type' => 'manual_routing_repair',
                'from_user_id' => $previousUserId,
                'to_user_id' => $user->id,
                'team_id' => $team->id,
                'required_role' => $role,
                'created_by_user_id' => $actor->id,
                'metadata' => [
                    'reason' => $reason,
                    'routingReasonCode' => $routingReason,
                    'dutyCoverageAssignmentId' => $currentRecipient['dutyCoverageAssignmentId'],
                ],
            ]);
            $notifications->emit(
                module: $locked->report_type === 'inspection' ? 'inspection' : 'report',
                eventType: 'workflow_reassigned',
                recordType: 'report',
                recordId: $locked->id,
                recordDisplayId: $locked->display_id,
                ownerUserId: (int) $locked->owner_user_id,
                actor: ['userId' => $actor->id, 'name' => $actor->name, 'email' => $actor->email],
                targetUserIds: [(int) $user->id],
                actionRequired: true,
                remarks: $reason,
                metadata: [
                    'workflowStage' => $locked->workflow_stage,
                    'nextActionRole' => $role,
                    'scopeTeamId' => (int) $team->id,
                    'routingReasonCode' => $routingReason,
                    'reportType' => $locked->report_type,
                    'reportUid' => $locked->report_uid,
                    'detailRouteKey' => $locked->report_uid,
                ],
                excludeOwner: true,
            );

            return null;
        }, 3);
        if ($repairError !== null) {
            return $this->invalid($repairError);
        }

        $this->info("Repaired routing for {$report->display_id}.");

        return self::SUCCESS;
    }

    private function invalid(string $message): int
    {
        $this->error($message);

        return self::INVALID;
    }
}
