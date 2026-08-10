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
            'password_hash' => '$2y$12$JmxM9saVPdc/zFB/DCjGi.pC5oZoMEtMffXRO8hauH1833B6QORo6',
        ],
        'incidentCommander' => [
            'name' => '[Live UAT] Incident Commander',
            'role' => 'Incident Commander',
            'scope' => RoleCatalog::SITE,
            'requires_team' => true,
            'email' => 'live-uat.ic@vmecc.amiosh.com',
            'password_hash' => '$2y$12$eMKgOl1W.ldcCexIJoH9feWNCkJURAOBACGj02trPj9NXZzLZ4D.q',
        ],
        'contractManager' => [
            'name' => '[Live UAT] Contract Manager',
            'role' => 'Contract Manager',
            'scope' => RoleCatalog::OFFICE,
            'requires_team' => false,
            'email' => 'live-uat.cm@vmecc.amiosh.com',
            'password_hash' => '$2y$12$YmpDCCzU83uscKHU/6DjI.pwH1OkI1X0H.F5pFMGbY5N0Mcj0JRkm',
        ],
        'humanResource' => [
            'name' => '[Live UAT] Human Resource',
            'role' => 'Human Resource',
            'scope' => RoleCatalog::OFFICE,
            'requires_team' => false,
            'email' => 'live-uat.hr@vmecc.amiosh.com',
            'password_hash' => '$2y$12$GvM6tT64ThPA6wgR5elZ/OQbuoiDVBjKSP09pS.QGnOla.RAmMxji',
        ],
        'finance' => [
            'name' => '[Live UAT] Finance',
            'role' => 'Finance',
            'scope' => RoleCatalog::OFFICE,
            'requires_team' => false,
            'email' => 'live-uat.finance@vmecc.amiosh.com',
            'password_hash' => '$2y$12$6oHneBCXbsucmBkxs6sUMOva08BlJ9gtTPMUjwACslzELicOYWpoy',
        ],
        'sysadmin' => [
            'name' => '[Live UAT] System Administrator',
            'role' => 'System Administrator',
            'scope' => RoleCatalog::GLOBAL,
            'requires_team' => false,
            'email' => 'live-uat.sysadmin@vmecc.amiosh.com',
            'password_hash' => '$2y$12$fbjPDhWHmTbM3ou8L6DAUu2.TsATqdQ/DmCPDvh1uaqc/xCHgOwQu',
        ],
    ],
];
