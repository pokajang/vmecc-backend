<?php

namespace App\Services;

final class AiHelperSensitiveDataGuard
{
    /** @return array<int, string> */
    public function categories(string $message): array
    {
        $patterns = [
            'api_secret' => '/\b(?:sk-[A-Za-z0-9_-]{20,}|Bearer\s+[A-Za-z0-9._~-]{20,})\b/i',
            'credential_value' => '/\b(?:password|passcode|kata laluan|pin)\s*[:=]\s*\S{4,}/iu',
            'identity_number' => '/\b(?:ic|nric|identity card|kad pengenalan)\D{0,16}\d{6}-?\d{2}-?\d{4}\b/iu',
            'bank_account' => '/\b(?:bank account|account number|akaun bank|nombor akaun)\D{0,16}\d(?:[ -]?\d){7,19}\b/iu',
        ];

        return collect($patterns)
            ->filter(fn (string $pattern) => preg_match($pattern, $message) === 1)
            ->keys()
            ->values()
            ->all();
    }
}
