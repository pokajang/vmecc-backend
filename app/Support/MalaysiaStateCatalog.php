<?php

namespace App\Support;

class MalaysiaStateCatalog
{
    private const STATES = [
        'Johor',
        'Kedah',
        'Kelantan',
        'Melaka',
        'Negeri Sembilan',
        'Pahang',
        'Perak',
        'Perlis',
        'Pulau Pinang',
        'Sabah',
        'Sarawak',
        'Selangor',
        'Terengganu',
        'W.P. Kuala Lumpur',
        'W.P. Labuan',
        'W.P. Putrajaya',
    ];

    private const LEGACY_STATE_ALIASES = [
        'kuala lumpur' => 'W.P. Kuala Lumpur',
        'labuan' => 'W.P. Labuan',
        'putrajaya' => 'W.P. Putrajaya',
    ];

    public static function values(): array
    {
        return self::STATES;
    }

    public static function normalize(?string $value): ?string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return null;
        }

        $normalizedRaw = mb_strtolower($raw);
        if (isset(self::LEGACY_STATE_ALIASES[$normalizedRaw])) {
            return self::LEGACY_STATE_ALIASES[$normalizedRaw];
        }

        foreach (self::STATES as $state) {
            if (mb_strtolower($state) === mb_strtolower($raw)) {
                return $state;
            }
        }

        return null;
    }

    public static function isValid(?string $value): bool
    {
        return self::normalize($value) !== null;
    }
}
