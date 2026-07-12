<?php

return [
    'enforcement_enabled' => env('INSPECTION_DUTY_ENFORCEMENT_ENABLED', false),
    'site_key' => env('INSPECTION_SITE_KEY', 'vmecc'),
    'site_timezone' => env('INSPECTION_SITE_TIMEZONE', 'Asia/Kuala_Lumpur'),
    'confirmation_ttl_minutes' => (int) env('INSPECTION_DUTY_CONFIRMATION_TTL_MINUTES', 10),
    'operations' => [
        'submit',
        'delete',
        'session-write',
        'session-submit',
        'review',
        'approve',
        'reject',
    ],
    'built_in_shifts' => [
        'day' => ['start' => '07:00', 'end' => '19:00'],
        'day12' => ['start' => '07:00', 'end' => '19:00'],
        'night' => ['start' => '19:00', 'end' => '07:00'],
        'night12' => ['start' => '19:00', 'end' => '07:00'],
        'normal' => ['start' => '08:30', 'end' => '17:30'],
    ],
];
