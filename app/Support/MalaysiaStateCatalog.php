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

