<?php

namespace App\Services;

use App\Models\Report;
use App\Models\User;
use WeakMap;

class ReportReadAuthorizationService
{
    /** @var WeakMap<User, array<string, bool>> */
    private WeakMap $modulePermissionCache;

    private const MODULE_PERMISSIONS = [
        'inspection' => 'reports.inspection.view',
        'erco' => 'reports.erco.view',
        'drill' => 'reports.drill.view',
        'fitness-test' => 'reports.fitness.view',
    ];

    private const PDF_REPORT_TYPES = [
        'inspection',
        'erco',
        'drill',
    ];

    private const PDF_REPORT_STATUSES = [
        'submitted',
        'reviewed',
        'approved',
        'rejected',
        'cancelled',
    ];

    public function __construct(
        private readonly AssignmentAuthorizationService $authorizationService,
    ) {
        $this->modulePermissionCache = new WeakMap;
    }

    public function canViewModule(User $user, mixed $reportType): bool
    {
        $reportType = $this->normalizeReportType($reportType);
        $permission = self::MODULE_PERMISSIONS[$reportType] ?? null;
        if (! $permission) {
            return false;
        }

        $cachedPermissions = $this->modulePermissionCache[$user] ?? [];
        if (array_key_exists($reportType, $cachedPermissions)) {
            return $cachedPermissions[$reportType];
        }

        $allowed = $this->authorizationService->hasPermission($user, "reports.manage|{$permission}");
        $cachedPermissions[$reportType] = $allowed;
        $this->modulePermissionCache[$user] = $cachedPermissions;

        return $allowed;
    }

    public function canDownloadPdf(User $user, Report $report): bool
    {
        $reportType = $this->normalizeReportType($report->report_type);
        $status = strtolower(trim((string) $report->status));

        return in_array($reportType, self::PDF_REPORT_TYPES, true)
            && in_array($status, self::PDF_REPORT_STATUSES, true)
            && $this->canViewModule($user, $reportType);
    }

    private function normalizeReportType(mixed $reportType): string
    {
        return strtolower(trim((string) $reportType));
    }
}
