<?php

return [
    'modules' => [
        'inspection' => [
            'permission' => 'reports.inspection.view',
            'upload_enabled' => true,
        ],
        'erco' => [
            'permission' => 'reports.erco.view',
            'upload_enabled' => true,
        ],
        'drill' => [
            'permission' => 'reports.drill.view',
            'upload_enabled' => (bool) env('REPORT_MEDIA_DRILL_UPLOAD_ENABLED', false),
        ],
    ],
    'thumbnail_max_dimension' => (int) env('REPORT_MEDIA_THUMBNAIL_MAX_DIMENSION', 480),
    'thumbnail_quality' => (int) env('REPORT_MEDIA_THUMBNAIL_QUALITY', 76),
    'minimum_disk_free_bytes' => (int) env('REPORT_MEDIA_MINIMUM_DISK_FREE_BYTES', 1073741824),
    'temporary_user_quota_bytes' => (int) env('REPORT_MEDIA_TEMPORARY_USER_QUOTA_BYTES', 134217728),
    'processing_lock_seconds' => (int) env('REPORT_MEDIA_PROCESSING_LOCK_SECONDS', 240),
    'lease_hours' => (int) env('REPORT_MEDIA_LEASE_HOURS', 168),
    'lease_absolute_days' => (int) env('REPORT_MEDIA_LEASE_ABSOLUTE_DAYS', 30),
    'require_heic_processor' => (bool) env('REPORT_MEDIA_REQUIRE_HEIC_PROCESSOR', true),
];
