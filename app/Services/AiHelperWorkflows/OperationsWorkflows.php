<?php

namespace App\Services\AiHelperWorkflows;

final class OperationsWorkflows
{
    /** @return array<int, array<string, mixed>> */
    public static function definitions(): array
    {
        return [
            self::workflow('roster.manage', 'roster-manage', 'Roster', 'Create and Publish Rosters', [
                ['key' => 'open_roster', 'kind' => 'open_menu', 'target' => 'Roster'],
                ['key' => 'change_roster', 'kind' => 'select', 'target' => 'Change'],
                ['key' => 'assign_teams', 'kind' => 'complete', 'targets' => ['Date Range', 'Team', 'Shift']],
                ['key' => 'save_draft', 'kind' => 'select', 'target' => 'Save draft'],
                ['key' => 'publish', 'kind' => 'review', 'targets' => ['Publish', 'Schedule Label']],
            ]),
            self::workflow('teams.manage', 'teams-manage', 'Teams', 'Create and Manage Teams', [
                ['key' => 'open_teams', 'kind' => 'open_menu', 'target' => 'Teams'],
                ['key' => 'add_team', 'kind' => 'select', 'target' => 'Add Team'],
                ['key' => 'complete_team', 'kind' => 'complete', 'targets' => ['Team Name', 'Members', 'Roles', 'Start Dates']],
                ['key' => 'save_team', 'kind' => 'review', 'targets' => ['Create', 'Save']],
                ['key' => 'verify_team', 'kind' => 'verify', 'targets' => ['Team Detail', 'Active Membership']],
            ]),
            self::workflow('reports.navigate', 'reports-navigation', 'Reports', 'Open or Create a Report', [
                ['key' => 'open_reports', 'kind' => 'open_menu', 'target' => 'Reports'],
                ['key' => 'select_report', 'kind' => 'complete', 'targets' => ['Reporting', 'Report Type']],
                ['key' => 'choose_record', 'kind' => 'choose', 'targets' => ['Records', 'New Report']],
                ['key' => 'review_record', 'kind' => 'verify', 'targets' => ['Detail', 'Timeline', 'Status', 'Available Actions']],
            ]),
        ];
    }

    private static function workflow(string $key, string $guideKey, string $module, string $action, array $steps): array
    {
        return [
            'key' => $key,
            'guide_key' => $guideKey,
            'task_keys' => [$key],
            'entity_keys' => [],
            'module' => $module,
            'action' => $action,
            'type' => $action,
            'source_labels' => match ($key) {
                'roster.manage' => ['Roster', 'Change', 'Save draft', 'Publish'],
                'teams.manage' => ['Teams', 'Add Team', 'Create', 'Edit team'],
                'reports.navigate' => ['Reports', 'Reporting', 'New Report'],
            },
            'steps' => $steps,
            'ui' => ['actions' => [], 'fields' => [], 'statuses' => []],
        ];
    }
}
