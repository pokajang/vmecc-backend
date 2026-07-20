<?php

namespace App\Services\WorkflowNotifications;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class WorkflowEmailModuleGate
{
    public const MODULES = [
        'report',
        'inspection',
        'leave',
        'overtime',
        'salary',
        'expense',
        'exceptional',
        'salary_assignment',
        'team',
        'roster',
    ];

    public static function enabledFor(?string $module, ?string $recordType): bool
    {
        $moduleGates = config('mail.workflow_notifications.modules', []);
        if (! is_array($moduleGates) || $moduleGates === []) {
            return true;
        }

        $normalizedModule = self::normalize($module);
        $normalizedRecordType = self::normalize($recordType);

        // Inspection notifications retain record_type=report for routing, so
        // their dedicated module switch must take precedence over that type.
        if ($normalizedModule === 'inspection' && array_key_exists('inspection', $moduleGates)) {
            return (bool) $moduleGates['inspection'];
        }

        if ($normalizedRecordType !== '' && array_key_exists($normalizedRecordType, $moduleGates)) {
            return (bool) $moduleGates[$normalizedRecordType];
        }

        if ($normalizedModule !== '' && array_key_exists($normalizedModule, $moduleGates)) {
            return (bool) $moduleGates[$normalizedModule];
        }

        return false;
    }

    public static function constrainNotificationQuery(Builder $query): Builder
    {
        $moduleGates = config('mail.workflow_notifications.modules', []);
        if (! is_array($moduleGates) || $moduleGates === []) {
            return $query;
        }

        $knownKeys = collect(array_keys($moduleGates))
            ->map(fn ($key) => self::normalize($key))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $enabledKeys = collect($moduleGates)
            ->filter(fn ($enabled) => (bool) $enabled)
            ->keys()
            ->map(fn ($key) => self::normalize($key))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($enabledKeys === []) {
            return $query->whereRaw('1 = 0');
        }

        $inspectionConfigured = in_array('inspection', $knownKeys, true);
        $inspectionEnabled = in_array('inspection', $enabledKeys, true);
        $standardEnabledKeys = array_values(array_diff($enabledKeys, ['inspection']));

        return $query->where(function (Builder $outer) use (
            $inspectionConfigured,
            $inspectionEnabled,
            $knownKeys,
            $standardEnabledKeys,
        ) {
            if ($inspectionConfigured && $inspectionEnabled) {
                $outer->whereRaw("LOWER(TRIM(COALESCE(module, ''))) = 'inspection'");
            }

            if ($standardEnabledKeys !== []) {
                $method = $inspectionConfigured && $inspectionEnabled ? 'orWhere' : 'where';
                $outer->{$method}(function (Builder $standard) use (
                    $inspectionConfigured,
                    $knownKeys,
                    $standardEnabledKeys,
                ) {
                    if ($inspectionConfigured) {
                        $standard->whereRaw("LOWER(TRIM(COALESCE(module, ''))) <> 'inspection'");
                    }

                    $standard->where(function (Builder $match) use ($knownKeys, $standardEnabledKeys) {
                        $match->whereIn(
                            DB::raw("LOWER(TRIM(COALESCE(record_type, '')))"),
                            $standardEnabledKeys,
                        )->orWhere(function (Builder $moduleFallback) use ($knownKeys, $standardEnabledKeys) {
                            $moduleFallback
                                ->whereNotIn(
                                    DB::raw("LOWER(TRIM(COALESCE(record_type, '')))"),
                                    $knownKeys,
                                )
                                ->whereIn(
                                    DB::raw("LOWER(TRIM(COALESCE(module, '')))"),
                                    $standardEnabledKeys,
                                );
                        });
                    });
                });
            }
        });
    }

    private static function normalize(mixed $value): string
    {
        return strtolower(trim((string) $value));
    }
}
