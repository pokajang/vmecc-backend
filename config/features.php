<?php

return [
    /*
    |--------------------------------------------------------------------------
    | OT/Payroll API rollout flags
    |--------------------------------------------------------------------------
    */
    'ot_payroll_reads_primary' => filter_var(
        env('FEATURE_OT_PAYROLL_READS_PRIMARY', true),
        FILTER_VALIDATE_BOOL,
        FILTER_NULL_ON_FAILURE
    ) ?? true,
    'ot_payroll_writes_primary' => filter_var(
        env('FEATURE_OT_PAYROLL_WRITES_PRIMARY', true),
        FILTER_VALIDATE_BOOL,
        FILTER_NULL_ON_FAILURE
    ) ?? true,
    'ot_payroll_local_fallback_enabled' => filter_var(
        env('FEATURE_OT_PAYROLL_LOCAL_FALLBACK_ENABLED', false),
        FILTER_VALIDATE_BOOL,
        FILTER_NULL_ON_FAILURE
    ) ?? false,
    'ot_payroll_migration_enabled' => filter_var(
        env('FEATURE_OT_PAYROLL_MIGRATION_ENABLED', false),
        FILTER_VALIDATE_BOOL,
        FILTER_NULL_ON_FAILURE
    ) ?? false,
    'holiday_guidance_leave_enabled' => filter_var(
        env('FEATURE_HOLIDAY_GUIDANCE_LEAVE_ENABLED', true),
        FILTER_VALIDATE_BOOL,
        FILTER_NULL_ON_FAILURE
    ) ?? true,
    'holiday_guidance_overtime_enabled' => filter_var(
        env('FEATURE_HOLIDAY_GUIDANCE_OVERTIME_ENABLED', true),
        FILTER_VALIDATE_BOOL,
        FILTER_NULL_ON_FAILURE
    ) ?? true,
    'holiday_guidance_staff_visibility_enabled' => filter_var(
        env('FEATURE_HOLIDAY_GUIDANCE_STAFF_VISIBILITY_ENABLED', false),
        FILTER_VALIDATE_BOOL,
        FILTER_NULL_ON_FAILURE
    ) ?? false,
    'holiday_guidance_cohort_user_ids' => array_values(array_filter(array_map(
        static fn ($id) => is_numeric(trim((string) $id)) ? (int) trim((string) $id) : null,
        explode(',', (string) env('FEATURE_HOLIDAY_GUIDANCE_COHORT_USER_IDS', ''))
    ))),
    'holiday_guidance_cohort_emails' => array_values(array_filter(array_map(
        static fn ($email) => trim((string) $email),
        explode(',', (string) env('FEATURE_HOLIDAY_GUIDANCE_COHORT_EMAILS', ''))
    ))),
];
