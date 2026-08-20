<?php

// Canonical SCBA catalog fixture. The full workbook roster is intentionally
// kept in version control before the frontend stops using its legacy fallback.
return [
    'source' => 'VMM SCBA Inspection Checklist.xlsx',
    'sections' => [
        ['key' => 'backPlate', 'title' => 'Back Plate', 'shortLabel' => 'Back Plate', 'fields' => [
            ['key' => 'backPlateHarnessCondition', 'label' => 'Back Plate & Harness', 'kind' => 'status'],
            ['key' => 'highPressureHose', 'label' => 'High Pressure Hose', 'kind' => 'status'],
            ['key' => 'pressureGauge', 'label' => 'Pressure Gauge', 'kind' => 'status'],
            ['key' => 'alarmDevice', 'label' => 'Alarm Device', 'kind' => 'status'],
            ['key' => 'demandValve', 'label' => 'Demand Valve', 'kind' => 'status'],
            ['key' => 'sealing', 'label' => 'Sealing', 'kind' => 'status'],
            ['key' => 'cleanliness', 'label' => 'Cleanliness', 'kind' => 'status'],
        ]],
        ['key' => 'cylinder', 'title' => 'Cylinder', 'shortLabel' => 'Cylinder', 'fields' => [
            ['key' => 'servicePressure', 'label' => 'Service Pressure (Bar)', 'kind' => 'text'],
            ['key' => 'containedPressure', 'label' => 'Contained Pressure (Bar)', 'kind' => 'text'],
            ['key' => 'physicalCondition', 'label' => 'Physical Condition', 'kind' => 'status'],
            ['key' => 'handwheelCondition', 'label' => 'Handwheel Condition', 'kind' => 'status'],
            ['key' => 'valveBodyCondition', 'label' => 'Valve Body Condition', 'kind' => 'status'],
            ['key' => 'screwPlugCondition', 'label' => 'Screw Plug Condition', 'kind' => 'status'],
            ['key' => 'cleanliness', 'label' => 'Cleanliness', 'kind' => 'status'],
        ]],
        ['key' => 'faceMask', 'title' => 'Face Mask', 'shortLabel' => 'Face Mask', 'fields' => [
            ['key' => 'visorCondition', 'label' => 'Visor Condition', 'kind' => 'status'],
            ['key' => 'ldvPort', 'label' => 'LDV Port', 'kind' => 'status'],
            ['key' => 'ldvReleaseButton', 'label' => 'LDV Release Button', 'kind' => 'status'],
            ['key' => 'leakTest', 'label' => 'Leak Test', 'kind' => 'status'],
            ['key' => 'speechDiaphragm', 'label' => 'Speech Diaphragm', 'kind' => 'status'],
            ['key' => 'harness', 'label' => 'Harness', 'kind' => 'status'],
            ['key' => 'neckStrap', 'label' => 'Neck Strap', 'kind' => 'status'],
        ]],
    ],
    // zlib+base64 JSON: {"Back Plate":[location,brand,serial,size,type], ...}
    'rows' => json_decode(gzuncompress(base64_decode('eNrFVtFKwzAU/ZWSJ4WiS9OkiW862ZMDsb6VPYQZZKza0fVFxH83btPadDe5QVFow9g55+aSc1POK7nSy3VyW+vOkIuqImXXtIakZF5e2nVC7fLxLNKKzO7uk5Nyo1tz2hOybwRHy0LaHNbyobYHBAQUcDEJQwqE6ASGKNAEhU+DMkgDnwLlR6DrVj+a1vVmjGaj/XqMebDcW9XfkfCihWdX6VUqHzowyqlLvWfU27VIyfSlXj0/2P/Ht0CcyZvz3XHbX3adNk+bZruyNwYa7b0ii1YwQHGsnzyCyyO4IrrrAlY4TBnRh8JzdyOA5Ub7SLOI6hEO0ggHacjBr6EWn6Nq37IzpoZpGY7GcLQcR+M4msDRChxN4mgKRdtPW5iGc4EiXFAHT1XYfnXwVQG3cUBk+Jo5tibH1xR4aoHdXo6J9uM+00uTzPV2Hco4DoRMNw6EzDUO9NNkM9AoKG0gU40D/VuucXPCb6QaF+MeDJ9oxuhfZBoX6xPN2zs9Fv8X')), true),
];
