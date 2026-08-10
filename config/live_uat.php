<?php

use App\Services\RoleCatalog;

return [
    'site_team_name' => 'Alpha',

    'personas' => [
        'trt' => [
            'name' => '[Live UAT] Tactical Response Team',
            'role' => 'Tactical Response Team',
            'scope' => RoleCatalog::SITE,
            'requires_team' => true,
            'email' => 'live-uat.trt@vmecc.amiosh.com',
        ],
        'incidentCommander' => [
            'name' => '[Live UAT] Incident Commander',
            'role' => 'Incident Commander',
            'scope' => RoleCatalog::SITE,
            'requires_team' => true,
            'email' => 'live-uat.ic@vmecc.amiosh.com',
        ],
        'contractManager' => [
            'name' => '[Live UAT] Contract Manager',
            'role' => 'Contract Manager',
            'scope' => RoleCatalog::OFFICE,
            'requires_team' => false,
            'email' => 'live-uat.cm@vmecc.amiosh.com',
        ],
        'humanResource' => [
            'name' => '[Live UAT] Human Resource',
            'role' => 'Human Resource',
            'scope' => RoleCatalog::OFFICE,
            'requires_team' => false,
            'email' => 'live-uat.hr@vmecc.amiosh.com',
        ],
        'finance' => [
            'name' => '[Live UAT] Finance',
            'role' => 'Finance',
            'scope' => RoleCatalog::OFFICE,
            'requires_team' => false,
            'email' => 'live-uat.finance@vmecc.amiosh.com',
        ],
        'sysadmin' => [
            'name' => '[Live UAT] System Administrator',
            'role' => 'System Administrator',
            'scope' => RoleCatalog::GLOBAL,
            'requires_team' => false,
            'email' => 'live-uat.sysadmin@vmecc.amiosh.com',
        ],
    ],
];
