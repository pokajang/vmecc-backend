<?php

namespace App\Services\WorkflowNotifications;

class WorkflowNotificationChannelPolicy
{
    public const IN_APP_ONLY = 'in_app_only';

    public const IN_APP_PLUS_IMMEDIATE_EMAIL = 'in_app_plus_immediate_email';

    public const IN_APP_PLUS_DIGEST = 'in_app_plus_digest';

    public const IN_APP_PLUS_IMMEDIATE_PLUS_DIGEST_REMINDER = 'in_app_plus_immediate_plus_digest_reminder';

    public static function isValid(string $policy): bool
    {
        return in_array($policy, [
            self::IN_APP_ONLY,
            self::IN_APP_PLUS_IMMEDIATE_EMAIL,
            self::IN_APP_PLUS_DIGEST,
            self::IN_APP_PLUS_IMMEDIATE_PLUS_DIGEST_REMINDER,
        ], true);
    }

    public static function sendsImmediateEmail(string $policy): bool
    {
        return in_array($policy, [
            self::IN_APP_PLUS_IMMEDIATE_EMAIL,
            self::IN_APP_PLUS_IMMEDIATE_PLUS_DIGEST_REMINDER,
        ], true);
    }

    public static function sendsDigest(string $policy): bool
    {
        return in_array($policy, [
            self::IN_APP_PLUS_DIGEST,
            self::IN_APP_PLUS_IMMEDIATE_PLUS_DIGEST_REMINDER,
        ], true);
    }

    public static function sendsReminder(string $policy): bool
    {
        return $policy === self::IN_APP_PLUS_IMMEDIATE_PLUS_DIGEST_REMINDER;
    }
}
