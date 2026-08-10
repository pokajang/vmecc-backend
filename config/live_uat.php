<?php

use App\Services\RoleCatalog;

return [
    'enabled' => env('LIVE_UAT_USERS_ENABLED', false),
    'allow_production' => env('LIVE_UAT_USERS_ALLOW_PRODUCTION', false),
    'site_team_id' => env('LIVE_UAT_SITE_TEAM_ID'),

    'personas' => [
        'trt' => [
            'name' => '[Live UAT] Tactical Response Team',
            'role' => 'Tactical Response Team',
            'scope' => RoleCatalog::SITE,
            'requires_team' => true,
            'email' => env('VMECC_LIVE_UAT_TRT_EMAIL'),
            'password' => env('VMECC_LIVE_UAT_TRT_PASSWORD'),
        ],
        'incidentCommander' => [
            'name' => '[Live UAT] Incident Commander',
            'role' => 'Incident Commander',
            'scope' => RoleCatalog::SITE,
            'requires_team' => true,
            'email' => env('VMECC_LIVE_UAT_INCIDENT_COMMANDER_EMAIL'),
            'password' => env('VMECC_LIVE_UAT_INCIDENT_COMMANDER_PASSWORD'),
        ],
        'contractManager' => [
            'name' => '[Live UAT] Contract Manager',
            'role' => 'Contract Manager',
            'scope' => RoleCatalog::OFFICE,
            'requires_team' => false,
            'email' => env('VMECC_LIVE_UAT_CONTRACT_MANAGER_EMAIL'),
            'password' => env('VMECC_LIVE_UAT_CONTRACT_MANAGER_PASSWORD'),
        ],
        'humanResource' => [
            'name' => '[Live UAT] Human Resource',
            'role' => 'Human Resource',
            'scope' => RoleCatalog::OFFICE,
            'requires_team' => false,
            'email' => env('VMECC_LIVE_UAT_HUMAN_RESOURCE_EMAIL'),
            'password' => env('VMECC_LIVE_UAT_HUMAN_RESOURCE_PASSWORD'),
        ],
        'finance' => [
            'name' => '[Live UAT] Finance',
            'role' => 'Finance',
            'scope' => RoleCatalog::OFFICE,
            'requires_team' => false,
            'email' => env('VMECC_LIVE_UAT_FINANCE_EMAIL'),
            'password' => env('VMECC_LIVE_UAT_FINANCE_PASSWORD'),
        ],
        'sysadmin' => [
            'name' => '[Live UAT] System Administrator',
            'role' => 'System Administrator',
            'scope' => RoleCatalog::GLOBAL,
            'requires_team' => false,
            'email' => env('VMECC_LIVE_UAT_SYSADMIN_EMAIL'),
            'password' => env('VMECC_LIVE_UAT_SYSADMIN_PASSWORD'),
        ],
    ],
];
