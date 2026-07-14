<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Inspection Report {{ (string) ($record['displayId'] ?? '') }}</title>
    @include('pdf.inspection-report.styles')
</head>
<body>

@php
    $viewData = is_array($viewData ?? null)
        ? $viewData
        : app(\App\Services\InspectionReports\InspectionReportViewDataBuilder::class)->build($record);
    $displayId = $viewData['displayId'];
    $status = $viewData['status'];
    $inspectionType = $viewData['inspectionType'];
    $location = $viewData['location'];
    $description = $viewData['description'];
    $reportEvidence = $viewData['reportEvidence'];
    $hse = $viewData['hse'];
    $sectionData = $viewData['sections'];
    $inspectionTypeKey = $viewData['inspectionTypeKey'];
    $isErAuxInspection = $viewData['isErAuxInspection'];
    $isFireExtinguisherInspection = $viewData['isFireExtinguisherInspection'];
    $isHydraulicInspection = $viewData['isHydraulicInspection'];
    $isFrtInspection = $viewData['isFrtInspection'];
    $isHighAngleInspection = $viewData['isHighAngleInspection'];
    $isScbaInspection = $viewData['isScbaInspection'];
    $isHseInspection = $viewData['isHseInspection'];
    $isGeneralInspection = $viewData['isGeneralInspection'];
    $checklist = $sectionData['checklist'];
    $erAuxChecks = $sectionData['erAuxChecks'];
    $fireExtinguisherChecks = $sectionData['fireExtinguisherChecks'];
    $hydraulicChecks = $sectionData['hydraulicChecks'];
    $frtDailyChecks = $sectionData['frtDailyChecks'];
    $frtOneOffChecks = $sectionData['frtOneOffChecks'];
    $highAngleChecks = $sectionData['highAngleChecks'];
    $scbaBackPlateChecks = $sectionData['scbaBackPlateChecks'];
    $scbaCylinderChecks = $sectionData['scbaCylinderChecks'];
    $scbaFaceMaskChecks = $sectionData['scbaFaceMaskChecks'];
    $scbaCustomSections = $sectionData['scbaCustomSections'];
    $erAuxInspectedBy = trim((string) ($record['erAuxInspectedBy'] ?? $record['er_aux_inspected_by'] ?? ''));
    $erAuxInspectionDate = trim((string) ($record['erAuxInspectionDate'] ?? $record['er_aux_inspection_date'] ?? ''));
    $fireExtinguisherInspectedBy = trim((string) ($record['fireExtinguisherInspectedBy'] ?? $record['fire_extinguisher_inspected_by'] ?? ''));
    $fireExtinguisherInspectionDate = trim((string) ($record['fireExtinguisherInspectionDate'] ?? $record['fire_extinguisher_inspection_date'] ?? ''));
    $frtInspectedBy = trim((string) ($record['frtInspectedBy'] ?? $record['frt_inspected_by'] ?? ''));
    $frtInspectionDate = trim((string) ($record['frtInspectionDate'] ?? $record['frt_inspection_date'] ?? ''));
    $frtShift = trim((string) ($record['frtShift'] ?? $record['frt_shift'] ?? ''));
    $frtTruckReference = is_array($record['frtTruckReference'] ?? null)
        ? $record['frtTruckReference']
        : (is_array($record['frt_truck_reference'] ?? null) ? $record['frt_truck_reference'] : []);
    $frtTruckPlate = trim((string) ($record['frtTruckPlateNo'] ?? $record['frt_truck_plate_no'] ?? $frtTruckReference['plateNo'] ?? $frtTruckReference['plate_no'] ?? $record['mainLocation'] ?? $record['main_location'] ?? ''));
    if (strtolower($frtTruckPlate) === 'fire truck') {
        $frtTruckPlate = trim((string) ($frtTruckReference['plateNo'] ?? $frtTruckReference['plate_no'] ?? ''));
    }
    $frtDailyRemarks = trim((string) ($record['frtDailyRemarks'] ?? $record['frt_daily_remarks'] ?? ''));
    $frtOneOffRemarks = trim((string) ($record['frtOneOffRemarks'] ?? $record['frt_one_off_remarks'] ?? ''));
    $highAngleInspectedBy = trim((string) ($record['highAngleInspectedBy'] ?? $record['high_angle_inspected_by'] ?? ''));
    $highAngleInspectionDate = trim((string) ($record['highAngleInspectionDate'] ?? $record['high_angle_inspection_date'] ?? ''));
    $scbaInspectedBy = trim((string) ($record['scbaInspectedBy'] ?? $record['scba_inspected_by'] ?? ''));
    $scbaInspectionDate = trim((string) ($record['scbaInspectionDate'] ?? $record['scba_inspection_date'] ?? ''));
    $hseInspectedBy = $hse['inspectedBy'];
    $hseInspectionDate = $hse['inspectionDate'];
    $hseSelections = $hse['selections'];
    $hseSelectionLabels = $hse['selectionLabels'];
    $hseSeverity = $hse['severity'];
    $hasHseObservation = $isHseInspection && $hse['hasObservation'];
    $submittedBy = trim((string) ($record['submittedBy'] ?? ''));
    $submittedAtRaw = trim((string) ($record['submittedAt'] ?? ''));
    $inspectionActor = is_array($record['inspectionActor'] ?? null) ? $record['inspectionActor'] : [];
    $formatRole = function ($role, $roleCode) {
        $role = trim((string) ($role ?? ''));
        $roleCode = trim((string) ($roleCode ?? ''));
        if ($role !== '' && $roleCode !== '') {
            return "{$role} ({$roleCode})";
        }
        return $role !== '' ? $role : $roleCode;
    };
    $submittedByRole = $formatRole($record['submittedByRole'] ?? '', $record['submittedByRoleCode'] ?? '');
    $inspectedByRole = $formatRole($inspectionActor['role'] ?? '', $inspectionActor['roleCode'] ?? '');
    $timeline = is_array($record['timeline'] ?? null) ? $record['timeline'] : [];
    $hydraulicCheckFields = [
        [
            'status' => 'physicalCondition',
            'status_snake' => 'physical_condition',
            'label' => 'Physical Condition',
            'remarks' => 'physicalConditionRemarks',
            'remarks_snake' => 'physical_condition_remarks',
            'photos' => 'physicalConditionPhotos',
            'photos_snake' => 'physical_condition_photos',
        ],
        [
            'status' => 'mechanicalCondition',
            'status_snake' => 'mechanical_condition',
            'label' => 'Mechanical Condition',
            'remarks' => 'mechanicalConditionRemarks',
            'remarks_snake' => 'mechanical_condition_remarks',
            'photos' => 'mechanicalConditionPhotos',
            'photos_snake' => 'mechanical_condition_photos',
        ],
        [
            'status' => 'noLeakage',
            'status_snake' => 'no_leakage',
            'label' => 'No Leakage',
            'remarks' => 'noLeakageRemarks',
            'remarks_snake' => 'no_leakage_remarks',
            'photos' => 'noLeakagePhotos',
            'photos_snake' => 'no_leakage_photos',
        ],
        [
            'status' => 'functionTest',
            'status_snake' => 'function_test',
            'label' => 'Function Test',
            'remarks' => 'functionTestRemarks',
            'remarks_snake' => 'function_test_remarks',
            'photos' => 'functionTestPhotos',
            'photos_snake' => 'function_test_photos',
        ],
    ];
    $scbaSections = [
        [
            'title' => 'Back Plate',
            'rows' => $scbaBackPlateChecks,
            'columns' => [
                ['label' => 'Location', 'camel' => 'location', 'snake' => 'location'],
                ['label' => 'Brand', 'camel' => 'brand', 'snake' => 'brand'],
                ['label' => 'Serial No.', 'camel' => 'serialNo', 'snake' => 'serial_no'],
                ['label' => 'Back Plate & Harness', 'camel' => 'backPlateHarnessCondition', 'snake' => 'back_plate_harness_condition'],
                ['label' => 'High Pressure Hose', 'camel' => 'highPressureHose', 'snake' => 'high_pressure_hose'],
                ['label' => 'Pressure Gauge', 'camel' => 'pressureGauge', 'snake' => 'pressure_gauge'],
                ['label' => 'Alarm Device', 'camel' => 'alarmDevice', 'snake' => 'alarm_device'],
                ['label' => 'Demand Valve', 'camel' => 'demandValve', 'snake' => 'demand_valve'],
                ['label' => 'Sealing', 'camel' => 'sealing', 'snake' => 'sealing'],
                ['label' => 'Cleanliness', 'camel' => 'cleanliness', 'snake' => 'cleanliness'],
                ['label' => 'Remarks', 'camel' => 'remarks', 'snake' => 'remarks'],
            ],
            'status_fields' => [
                ['label' => 'Back Plate & Harness', 'status' => 'backPlateHarnessCondition', 'status_snake' => 'back_plate_harness_condition', 'remarks' => 'backPlateHarnessConditionRemarks', 'remarks_snake' => 'back_plate_harness_condition_remarks', 'photos' => 'backPlateHarnessConditionPhotos', 'photos_snake' => 'back_plate_harness_condition_photos'],
                ['label' => 'High Pressure Hose', 'status' => 'highPressureHose', 'status_snake' => 'high_pressure_hose', 'remarks' => 'highPressureHoseRemarks', 'remarks_snake' => 'high_pressure_hose_remarks', 'photos' => 'highPressureHosePhotos', 'photos_snake' => 'high_pressure_hose_photos'],
                ['label' => 'Pressure Gauge', 'status' => 'pressureGauge', 'status_snake' => 'pressure_gauge', 'remarks' => 'pressureGaugeRemarks', 'remarks_snake' => 'pressure_gauge_remarks', 'photos' => 'pressureGaugePhotos', 'photos_snake' => 'pressure_gauge_photos'],
                ['label' => 'Alarm Device', 'status' => 'alarmDevice', 'status_snake' => 'alarm_device', 'remarks' => 'alarmDeviceRemarks', 'remarks_snake' => 'alarm_device_remarks', 'photos' => 'alarmDevicePhotos', 'photos_snake' => 'alarm_device_photos'],
                ['label' => 'Demand Valve', 'status' => 'demandValve', 'status_snake' => 'demand_valve', 'remarks' => 'demandValveRemarks', 'remarks_snake' => 'demand_valve_remarks', 'photos' => 'demandValvePhotos', 'photos_snake' => 'demand_valve_photos'],
                ['label' => 'Sealing', 'status' => 'sealing', 'status_snake' => 'sealing', 'remarks' => 'sealingRemarks', 'remarks_snake' => 'sealing_remarks', 'photos' => 'sealingPhotos', 'photos_snake' => 'sealing_photos'],
                ['label' => 'Cleanliness', 'status' => 'cleanliness', 'status_snake' => 'cleanliness', 'remarks' => 'cleanlinessRemarks', 'remarks_snake' => 'cleanliness_remarks', 'photos' => 'cleanlinessPhotos', 'photos_snake' => 'cleanliness_photos'],
            ],
        ],
        [
            'title' => 'Cylinder',
            'rows' => $scbaCylinderChecks,
            'columns' => [
                ['label' => 'Location', 'camel' => 'location', 'snake' => 'location'],
                ['label' => 'Brand', 'camel' => 'brand', 'snake' => 'brand'],
                ['label' => 'Serial No.', 'camel' => 'serialNo', 'snake' => 'serial_no'],
                ['label' => 'Size (L)', 'camel' => 'size', 'snake' => 'size'],
                ['label' => 'Type', 'camel' => 'cylinderType', 'snake' => 'cylinder_type'],
                ['label' => 'Service Pressure', 'camel' => 'servicePressure', 'snake' => 'service_pressure'],
                ['label' => 'Contained Pressure', 'camel' => 'containedPressure', 'snake' => 'contained_pressure'],
                ['label' => 'Physical Condition', 'camel' => 'physicalCondition', 'snake' => 'physical_condition'],
                ['label' => 'Handwheel Condition', 'camel' => 'handwheelCondition', 'snake' => 'handwheel_condition'],
                ['label' => 'Valve Body Condition', 'camel' => 'valveBodyCondition', 'snake' => 'valve_body_condition'],
                ['label' => 'Screw Plug Condition', 'camel' => 'screwPlugCondition', 'snake' => 'screw_plug_condition'],
                ['label' => 'Cleanliness', 'camel' => 'cleanliness', 'snake' => 'cleanliness'],
                ['label' => 'Remarks', 'camel' => 'remarks', 'snake' => 'remarks'],
            ],
            'status_fields' => [
                ['label' => 'Physical Condition', 'status' => 'physicalCondition', 'status_snake' => 'physical_condition', 'remarks' => 'physicalConditionRemarks', 'remarks_snake' => 'physical_condition_remarks', 'photos' => 'physicalConditionPhotos', 'photos_snake' => 'physical_condition_photos'],
                ['label' => 'Handwheel Condition', 'status' => 'handwheelCondition', 'status_snake' => 'handwheel_condition', 'remarks' => 'handwheelConditionRemarks', 'remarks_snake' => 'handwheel_condition_remarks', 'photos' => 'handwheelConditionPhotos', 'photos_snake' => 'handwheel_condition_photos'],
                ['label' => 'Valve Body Condition', 'status' => 'valveBodyCondition', 'status_snake' => 'valve_body_condition', 'remarks' => 'valveBodyConditionRemarks', 'remarks_snake' => 'valve_body_condition_remarks', 'photos' => 'valveBodyConditionPhotos', 'photos_snake' => 'valve_body_condition_photos'],
                ['label' => 'Screw Plug Condition', 'status' => 'screwPlugCondition', 'status_snake' => 'screw_plug_condition', 'remarks' => 'screwPlugConditionRemarks', 'remarks_snake' => 'screw_plug_condition_remarks', 'photos' => 'screwPlugConditionPhotos', 'photos_snake' => 'screw_plug_condition_photos'],
                ['label' => 'Cleanliness', 'status' => 'cleanliness', 'status_snake' => 'cleanliness', 'remarks' => 'cleanlinessRemarks', 'remarks_snake' => 'cleanliness_remarks', 'photos' => 'cleanlinessPhotos', 'photos_snake' => 'cleanliness_photos'],
            ],
        ],
        [
            'title' => 'Face Mask',
            'rows' => $scbaFaceMaskChecks,
            'columns' => [
                ['label' => 'Location', 'camel' => 'location', 'snake' => 'location'],
                ['label' => 'Brand', 'camel' => 'brand', 'snake' => 'brand'],
                ['label' => 'Serial No.', 'camel' => 'serialNo', 'snake' => 'serial_no'],
                ['label' => 'Visor Condition', 'camel' => 'visorCondition', 'snake' => 'visor_condition'],
                ['label' => 'LDV Port', 'camel' => 'ldvPort', 'snake' => 'ldv_port'],
                ['label' => 'LDV Release Button', 'camel' => 'ldvReleaseButton', 'snake' => 'ldv_release_button'],
                ['label' => 'Leak Test', 'camel' => 'leakTest', 'snake' => 'leak_test'],
                ['label' => 'Speech Diaphragm', 'camel' => 'speechDiaphragm', 'snake' => 'speech_diaphragm'],
                ['label' => 'Harness', 'camel' => 'harness', 'snake' => 'harness'],
                ['label' => 'Neck Strap', 'camel' => 'neckStrap', 'snake' => 'neck_strap'],
                ['label' => 'Remarks', 'camel' => 'remarks', 'snake' => 'remarks'],
            ],
            'status_fields' => [
                ['label' => 'Visor Condition', 'status' => 'visorCondition', 'status_snake' => 'visor_condition', 'remarks' => 'visorConditionRemarks', 'remarks_snake' => 'visor_condition_remarks', 'photos' => 'visorConditionPhotos', 'photos_snake' => 'visor_condition_photos'],
                ['label' => 'LDV Port', 'status' => 'ldvPort', 'status_snake' => 'ldv_port', 'remarks' => 'ldvPortRemarks', 'remarks_snake' => 'ldv_port_remarks', 'photos' => 'ldvPortPhotos', 'photos_snake' => 'ldv_port_photos'],
                ['label' => 'LDV Release Button', 'status' => 'ldvReleaseButton', 'status_snake' => 'ldv_release_button', 'remarks' => 'ldvReleaseButtonRemarks', 'remarks_snake' => 'ldv_release_button_remarks', 'photos' => 'ldvReleaseButtonPhotos', 'photos_snake' => 'ldv_release_button_photos'],
                ['label' => 'Leak Test', 'status' => 'leakTest', 'status_snake' => 'leak_test', 'remarks' => 'leakTestRemarks', 'remarks_snake' => 'leak_test_remarks', 'photos' => 'leakTestPhotos', 'photos_snake' => 'leak_test_photos'],
                ['label' => 'Speech Diaphragm', 'status' => 'speechDiaphragm', 'status_snake' => 'speech_diaphragm', 'remarks' => 'speechDiaphragmRemarks', 'remarks_snake' => 'speech_diaphragm_remarks', 'photos' => 'speechDiaphragmPhotos', 'photos_snake' => 'speech_diaphragm_photos'],
                ['label' => 'Harness', 'status' => 'harness', 'status_snake' => 'harness', 'remarks' => 'harnessRemarks', 'remarks_snake' => 'harness_remarks', 'photos' => 'harnessPhotos', 'photos_snake' => 'harness_photos'],
                ['label' => 'Neck Strap', 'status' => 'neckStrap', 'status_snake' => 'neck_strap', 'remarks' => 'neckStrapRemarks', 'remarks_snake' => 'neck_strap_remarks', 'photos' => 'neckStrapPhotos', 'photos_snake' => 'neck_strap_photos'],
            ],
        ],
    ];
    $toSnake = function ($value) {
        $value = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', (string) $value);
        return strtolower((string) $value);
    };
    foreach ($scbaCustomSections as $customSection) {
        $fields = array_values(array_filter(is_array($customSection['fields'] ?? null) ? $customSection['fields'] : [], function ($field) {
            return is_array($field) && trim((string) ($field['key'] ?? '')) !== '' && trim((string) ($field['label'] ?? '')) !== '';
        }));
        $rows = array_values(array_filter(is_array($customSection['rows'] ?? null) ? $customSection['rows'] : [], function ($item) {
            return is_array($item) && ($item['removed'] ?? false) !== true && (trim((string) ($item['serialNo'] ?? $item['serial_no'] ?? '')) !== '' || trim((string) ($item['brand'] ?? '')) !== '');
        }));
        if (count($fields) === 0 && count($rows) === 0) {
            continue;
        }
        $columns = [
            ['label' => 'Location', 'camel' => 'location', 'snake' => 'location'],
            ['label' => 'Brand', 'camel' => 'brand', 'snake' => 'brand'],
            ['label' => 'Serial No.', 'camel' => 'serialNo', 'snake' => 'serial_no'],
        ];
        $statusFields = [];
        foreach ($fields as $field) {
            $fieldKey = trim((string) ($field['key'] ?? ''));
            $fieldLabel = trim((string) ($field['label'] ?? $fieldKey));
            $remarksKey = $fieldKey.'Remarks';
            $photosKey = $fieldKey.'Photos';
            $columns[] = ['label' => $fieldLabel, 'camel' => $fieldKey, 'snake' => $toSnake($fieldKey)];
            $statusFields[] = [
                'label' => $fieldLabel,
                'status' => $fieldKey,
                'status_snake' => $toSnake($fieldKey),
                'remarks' => $remarksKey,
                'remarks_snake' => $toSnake($remarksKey),
                'photos' => $photosKey,
                'photos_snake' => $toSnake($photosKey),
            ];
        }
        $columns[] = ['label' => 'Remarks', 'camel' => 'remarks', 'snake' => 'remarks'];
        $scbaSections[] = [
            'title' => trim((string) ($customSection['title'] ?? 'Custom SCBA Section')),
            'rows' => $rows,
            'columns' => $columns,
            'status_fields' => $statusFields,
        ];
    }
    $hasScbaChecks = collect($scbaSections)->contains(fn ($section) => count($section['rows']) > 0);
    $frtDailyGroups = [];
    foreach ($frtDailyChecks as $check) {
        $group = trim((string) ($check['location'] ?? ''));
        $key = $group !== '' ? $group : 'FIRE TRUCK';
        if (! isset($frtDailyGroups[$key])) {
            $frtDailyGroups[$key] = [
                'title' => $key,
                'rows' => [],
            ];
        }
        $frtDailyGroups[$key]['rows'][] = $check;
    }
    $frtOneOffGroups = [];
    foreach ($frtOneOffChecks as $check) {
        $group = trim((string) ($check['location'] ?? ''));
        $key = $group !== '' ? $group : 'FIRE TRUCK';
        if (! isset($frtOneOffGroups[$key])) {
            $frtOneOffGroups[$key] = [
                'title' => $key,
                'rows' => [],
            ];
        }
        $frtOneOffGroups[$key]['rows'][] = $check;
    }
    $hasFrtChecks = count($frtDailyChecks) > 0 || count($frtOneOffChecks) > 0;
    $highAngleGroupLabel = function (array $check): string {
        $parts = [];
        $locationPart = trim((string) ($check['location'] ?? ''));
        $subLocationPart = trim((string) ($check['subLocation'] ?? $check['sub_location'] ?? ''));
        if ($locationPart !== '' && strcasecmp($locationPart, 'N/A') !== 0) {
            $parts[] = $locationPart;
        }
        if ($subLocationPart !== '' && strcasecmp($subLocationPart, 'N/A') !== 0) {
            $parts[] = $subLocationPart;
        }
        return count($parts) > 0 ? implode(' - ', $parts) : 'General Kit Items';
    };
    $highAngleGroups = [];
    foreach ($highAngleChecks as $check) {
        $key = trim((string) ($check['location'] ?? '')).'::'.trim((string) ($check['subLocation'] ?? $check['sub_location'] ?? ''));
        if (! isset($highAngleGroups[$key])) {
            $highAngleGroups[$key] = [
                'title' => $highAngleGroupLabel($check),
                'rows' => [],
            ];
        }
        $highAngleGroups[$key]['rows'][] = $check;
    }

    $isDisplayablePhoto = function ($photo): bool {
        if (!is_array($photo)) return false;
        if (($photo['imageUnavailable'] ?? false) === true) return true;
        $url = trim((string) ($photo['url'] ?? ''));
        if ($url === '') return false;
        return (bool) preg_match('/^data:image\/[a-z0-9.+-]+;base64,/i', $url);
    };
    $filterInlinePhotos = function ($items) use ($isDisplayablePhoto) {
        $rows = is_array($items) ? $items : [];
        return array_values(array_filter($rows, $isDisplayablePhoto));
    };
    $compactText = function ($value): string {
        return preg_replace('/\s+/u', ' ', trim((string) $value));
    };
    $isCompactText = function ($value) use ($compactText): bool {
        $raw = trim((string) $value);
        if ($raw === '' || preg_match('/[\r\n]/', $raw)) {
            return false;
        }

        return mb_strlen($compactText($raw), 'UTF-8') <= 120;
    };
    $compactBlock = function (string $title, string $label, string $value) use ($compactText): array {
        return [
            'title' => $compactText($title),
            'label' => $compactText($label),
            'value' => $compactText($value),
        ];
    };
    $renderCompactBlocks = function (array $blocks): string {
        $blocks = array_values(array_filter($blocks, fn ($block) => trim((string) ($block['value'] ?? '')) !== ''));
        if (count($blocks) === 0) {
            return '';
        }

        $html = '<table class="compact-info-grid">';
        foreach (array_chunk($blocks, 2) as $row) {
            $html .= '<tr>';
            foreach ($row as $block) {
                $html .= '<td>';
                $html .= '<div class="compact-info-title">'.e((string) ($block['title'] ?? '')).'</div>';
                if (trim((string) ($block['label'] ?? '')) !== '') {
                    $html .= '<div class="compact-info-label">'.e((string) $block['label']).'</div>';
                }
                $html .= '<div class="compact-info-value">'.e((string) ($block['value'] ?? '')).'</div>';
                $html .= '</td>';
            }
            if (count($row) === 1) {
                $html .= '<td class="compact-info-empty"></td>';
            }
            $html .= '</tr>';
        }
        $html .= '</table>';

        return $html;
    };
    $rawInspectionIssues = is_array($record['inspectionIssues'] ?? null)
        ? $record['inspectionIssues']
        : (is_array($record['inspection_issues'] ?? null)
            ? $record['inspection_issues']
            : (($isGeneralInspection || $isHseInspection) && is_array($record['issues'] ?? null)
                ? $record['issues']
                : []));
    $inspectionIssues = [];
    foreach ($rawInspectionIssues as $issue) {
        if (! is_array($issue)) {
            continue;
        }
        $issueDescription = trim((string) ($issue['description'] ?? $issue['details'] ?? ''));
        $issueAction = trim((string) ($issue['actionRequired'] ?? $issue['action_required'] ?? ''));
        $issuePhotos = $filterInlinePhotos($issue['photos'] ?? $issue['issue_photos'] ?? []);
        if ($issueDescription === '' && $issueAction === '' && count($issuePhotos) === 0) {
            continue;
        }
        $inspectionIssues[] = [
            'description' => $issueDescription,
            'actionRequired' => $issueAction,
            'photos' => $issuePhotos,
        ];
    }
    $submittedEntry = collect($timeline)->first(function ($entry) {
        $action = strtolower(trim((string) ($entry['action'] ?? '')));
        return $action === 'submitted' || $action === 'resubmitted';
    });
    $reviewedEntry = collect($timeline)->first(function ($entry) {
        $action = strtolower(trim((string) ($entry['action'] ?? '')));
        return in_array($action, ['reviewed', 'review', 'checked'], true);
    });
    $approvedEntry = collect($timeline)->first(function ($entry) {
        $action = strtolower(trim((string) ($entry['action'] ?? '')));
        return in_array($action, ['approved', 'approve'], true);
    });

    $fmtDateTime = function ($value) {
        $raw = trim((string) $value);
        if ($raw === '') return '';
        try {
            return \Carbon\Carbon::parse($raw)->format('d M Y, H:i');
        } catch (\Throwable) {
            return $raw;
        }
    };
    $entryActorRole = function ($entry) use ($formatRole) {
        if (!is_array($entry)) return '';
        $meta = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
        return $formatRole(
            $entry['actorRole'] ?? $meta['actorRole'] ?? '',
            $entry['actorRoleCode'] ?? $meta['actorRoleCode'] ?? ''
        );
    };

    $submittedAt = $submittedAtRaw !== '' ? $fmtDateTime($submittedAtRaw) : '';
    if ($submittedAt === '' && is_array($submittedEntry)) {
        $submittedAt = $fmtDateTime($submittedEntry['at'] ?? '');
    }
    if ($submittedBy === '' && is_array($submittedEntry)) {
        $submittedBy = trim((string) ($submittedEntry['by'] ?? ''));
    }

    $hasDetailedInspectionSection =
        ($isErAuxInspection && count($erAuxChecks) > 0)
        || ($isFireExtinguisherInspection && count($fireExtinguisherChecks) > 0)
        || ($isHydraulicInspection && count($hydraulicChecks) > 0)
        || ($isFrtInspection && $hasFrtChecks)
        || ($isHighAngleInspection && count($highAngleChecks) > 0)
        || ($isScbaInspection && $hasScbaChecks)
        || $hasHseObservation;
    $shouldRenderDescriptionCard = ! $hasDetailedInspectionSection;
    $shouldRenderChecklistCard = count($checklist) > 0 && ! $hasDetailedInspectionSection;

@endphp

@include('pdf.inspection-report.partials.header')
@include('pdf.inspection-report.partials.overview')
@include('pdf.inspection-report.partials.summary')

@include('pdf.inspection-report.sections.hse')

@include('pdf.inspection-report.sections.fire-extinguisher')

@include('pdf.inspection-report.sections.hydraulic')

@include('pdf.inspection-report.sections.frt')

@include('pdf.inspection-report.sections.scba')

@include('pdf.inspection-report.sections.high-angle')

@include('pdf.inspection-report.sections.er-aux')

@include('pdf.inspection-report.sections.findings')

@include('pdf.inspection-report.partials.report-evidence')

@include('pdf.inspection-report.partials.workflow-signoffs')

</body>
</html>
