<?php

return [
    'thumbnail_max_dimension' => (int) env('REPORT_MEDIA_THUMBNAIL_MAX_DIMENSION', 480),
    'thumbnail_quality' => (int) env('REPORT_MEDIA_THUMBNAIL_QUALITY', 76),
    'minimum_disk_free_bytes' => (int) env('REPORT_MEDIA_MINIMUM_DISK_FREE_BYTES', 1073741824),
    'temporary_user_quota_bytes' => (int) env('REPORT_MEDIA_TEMPORARY_USER_QUOTA_BYTES', 134217728),
    'processing_lock_seconds' => (int) env('REPORT_MEDIA_PROCESSING_LOCK_SECONDS', 120),
    'require_heic_processor' => (bool) env('REPORT_MEDIA_REQUIRE_HEIC_PROCESSOR', true),
];
