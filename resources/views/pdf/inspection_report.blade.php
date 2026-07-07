<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Inspection Report {{ (string) ($record['displayId'] ?? '') }}</title>
    <style>
        @page { size: A4; margin: 14mm 14mm 24mm 14mm; }
        * { box-sizing: border-box; }
        body {
            font-family: Helvetica, Arial, sans-serif;
            color: #111827;
            font-size: 10px;
            line-height: 1.35;
            margin: 0;
            padding-bottom: 10mm;
        }
        .header {
            display: table;
            width: 100%;
            margin-bottom: 10px;
            border-bottom: 2px solid #0b948f;
            padding-bottom: 8px;
        }
        .header-left { display: table-cell; vertical-align: bottom; }
        .header-right { display: table-cell; vertical-align: bottom; text-align: right; }
        .report-title {
            font-size: 15px;
            font-weight: 700;
            color: #0b948f;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }
        .report-subtitle { font-size: 9px; color: #6b7280; margin-top: 1px; }
        .report-id { font-size: 13px; font-weight: 700; color: #111827; }
        .status-badge {
            display: inline-block;
            font-size: 8.5px;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 10px;
            margin-top: 3px;
            background: #dbeafe;
            color: #1e40af;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }
        .card {
            border: 1px solid #d1d5db;
            margin-bottom: 8px;
            page-break-inside: avoid;
        }
        .card-head {
            background: #f3f4f6;
            border-bottom: 1px solid #d1d5db;
            font-weight: 700;
            font-size: 9px;
            padding: 4px 8px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #374151;
        }
        .card-body { padding: 7px 8px; }
        .meta-grid {
            display: table;
            width: 100%;
            table-layout: fixed;
        }
        .meta-cell {
            display: table-cell;
            width: 33.333%;
            padding: 0 4px 5px 0;
            vertical-align: top;
        }
        .meta-cell:last-child { padding-right: 0; }
        .meta-grid-4 .meta-cell { width: 25%; }
        .meta-label { font-size: 8.5px; color: #6b7280; margin-bottom: 1px; }
        .meta-value { font-size: 10px; font-weight: 600; word-break: break-word; }
        .text-block-label { font-size: 8.5px; color: #6b7280; margin-bottom: 2px; }
        .text-block-value {
            font-size: 10px;
            line-height: 1.5;
            word-break: break-word;
            white-space: pre-wrap;
        }
        .divider { height: 1px; background: #e5e7eb; margin: 6px 0; }
        .checklist-list {
            margin: 0;
            padding-left: 14px;
        }
        .checklist-list li {
            margin: 0 0 2px;
            font-size: 9.5px;
            line-height: 1.35;
        }
        table.workflow {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        .workflow th, .workflow td {
            border: 1px solid #d1d5db;
            padding: 5px 8px;
            vertical-align: top;
        }
        .workflow th {
            background: #f3f4f6;
            font-weight: 700;
            font-size: 8.5px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #374151;
            text-align: left;
            width: 33.333%;
        }
        .workflow td { min-height: 36px; font-size: 9px; }
        table.hydraulic-checks {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        .hydraulic-checks th,
        .hydraulic-checks td {
            border: 1px solid #d1d5db;
            padding: 4px 5px;
            vertical-align: top;
            font-size: 8.2px;
            line-height: 1.3;
            word-break: break-word;
        }
        .hydraulic-checks th {
            background: #f3f4f6;
            color: #374151;
            font-weight: 700;
            text-align: left;
        }
        .pill {
            display: inline-block;
            margin-left: 3px;
            padding: 1px 4px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            background: #f9fafb;
            color: #4b5563;
            font-size: 7.5px;
            font-weight: 700;
        }
        .pending { color: #9ca3af; font-style: italic; font-size: 8.5px; }
        .person-name { font-weight: 600; font-size: 9.5px; color: #111827; }
        .person-meta { font-size: 8.5px; color: #6b7280; margin-top: 2px; }
        .person-remarks {
            font-size: 8.5px;
            color: #4b5563;
            margin-top: 3px;
            line-height: 1.35;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .photo-grid {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            margin-top: 2px;
        }
        .photo-grid td {
            width: 50%;
            vertical-align: top;
            padding: 4px;
        }
        .photo-card {
            border: none;
            border-radius: 0;
            overflow: visible;
            page-break-inside: avoid;
        }
        .photo-figure {
            display: inline-block;
            max-width: 100%;
        }
        .photo-image-wrap {
            background: transparent;
            text-align: left;
            padding: 6px 0 0;
        }
        .photo-image {
            max-width: 100%;
            max-height: 180px;
            width: auto;
            height: auto;
            display: block;
            margin: 0;
            vertical-align: top;
        }
        .photo-caption {
            padding: 5px 0 0;
            border-top: none;
        }
        .photo-description {
            margin-top: 2px;
            font-size: 8.5px;
            color: #4b5563;
            line-height: 1.35;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .issue-block {
            border: 1px solid #e5e7eb;
            padding: 6px 7px;
            margin-bottom: 7px;
            page-break-inside: avoid;
        }
        .issue-title {
            font-weight: 700;
            font-size: 9.5px;
            color: #111827;
            margin-bottom: 4px;
        }
        .compact-info-grid {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 4px;
            table-layout: fixed;
            margin-top: 4px;
        }
        .compact-info-grid td {
            width: 50%;
            border: 1px solid #e5e7eb;
            padding: 4px 6px;
            vertical-align: top;
            page-break-inside: avoid;
        }
        .compact-info-grid td.compact-info-empty {
            border: none;
            padding: 0;
        }
        .compact-info-title {
            font-size: 8.4px;
            font-weight: 700;
            color: #374151;
            margin-bottom: 2px;
            line-height: 1.25;
        }
        .compact-info-label {
            font-size: 8px;
            color: #6b7280;
            margin-bottom: 1px;
            line-height: 1.25;
        }
        .compact-info-value {
            font-size: 9.5px;
            color: #111827;
            line-height: 1.35;
            word-break: break-word;
            white-space: pre-wrap;
        }
        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            height: 10mm;
            border-top: 1px solid #e5e7eb;
            display: table;
            width: 100%;
            padding: 3px 14mm;
        }
        .footer-left { display: table-cell; vertical-align: middle; font-size: 7.5px; color: #9ca3af; }
        .footer-right { display: table-cell; vertical-align: middle; text-align: right; font-size: 7.5px; color: #9ca3af; }
    </style>
</head>
<body>

@php
    $displayId = (string) ($record['displayId'] ?? '-');
    $status = (string) ($record['status'] ?? 'Submitted');
    $inspectionType = trim((string) ($record['incidentType'] ?? $record['inspectionType'] ?? $record['inspection_type'] ?? ''));
    $location = trim((string) ($record['location'] ?? $record['selectedLocation'] ?? ''));
    $description = (string) ($record['description'] ?? '');
    $normalizeInspectionType = function ($value): string {
        $normalized = strtolower((string) $value);
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized);
        return trim((string) preg_replace('/\s+/', ' ', (string) $normalized));
    };
    $inspectionTypeKey = $normalizeInspectionType($inspectionType);
    $isErAuxInspection = str_contains($inspectionTypeKey, 'er aux')
        || str_contains($inspectionTypeKey, 'emergency response auxiliary');
    $isFireExtinguisherInspection = str_contains($inspectionTypeKey, 'fire extinguisher');
    $isHydraulicInspection = str_contains($inspectionTypeKey, 'hydraulic rescue tools')
        || str_contains($inspectionTypeKey, 'hydraulic');
    $isFrtInspection = str_contains($inspectionTypeKey, 'frt daily')
        || str_contains($inspectionTypeKey, 'fire truck daily')
        || str_contains($inspectionTypeKey, 'fire truck readiness');
    $isHighAngleInspection = str_contains($inspectionTypeKey, 'high angle');
    $isScbaInspection = (bool) preg_match('/(^|\s)scba(\s|$)/', $inspectionTypeKey);
    $isHseInspection = str_contains($inspectionTypeKey, 'health safety environment')
        || (bool) preg_match('/(^|\s)hse(\s|$)/', $inspectionTypeKey);
    $isGeneralInspection = $inspectionTypeKey === 'general inspection'
        || str_contains($inspectionTypeKey, 'general inspection');
    $itemMatchesCurrentInspection = function ($item) use ($inspectionTypeKey, $normalizeInspectionType): bool {
        if ($inspectionTypeKey === '' || !is_array($item)) {
            return true;
        }
        $itemType = trim((string) ($item['inspectionType'] ?? $item['incidentType'] ?? $item['type'] ?? ''));
        if ($itemType === '') {
            return true;
        }
        return $normalizeInspectionType($itemType) === $inspectionTypeKey;
    };
    $checklist = array_values(array_filter(is_array($record['checklist'] ?? null) ? $record['checklist'] : [], function ($item) {
        return is_array($item)
            && ($item['selected'] ?? true) !== false
            && trim((string) ($item['label'] ?? '')) !== '';
    }));
    $checklist = array_values(array_filter($checklist, $itemMatchesCurrentInspection));
    $erAuxChecks = array_values(array_filter(is_array($record['erAuxChecks'] ?? null) ? $record['erAuxChecks'] : (is_array($record['er_aux_checks'] ?? null) ? $record['er_aux_checks'] : []), function ($item) {
        return is_array($item) && trim((string) ($item['equipment'] ?? '')) !== '';
    }));
    $fireExtinguisherChecks = array_values(array_filter(is_array($record['fireExtinguisherChecks'] ?? null) ? $record['fireExtinguisherChecks'] : (is_array($record['fire_extinguisher_checks'] ?? null) ? $record['fire_extinguisher_checks'] : []), function ($item) {
        return is_array($item) && (
            trim((string) ($item['idLocNo'] ?? $item['id_loc_no'] ?? '')) !== ''
            || trim((string) ($item['barcodeNo'] ?? $item['barcode_no'] ?? '')) !== ''
        );
    }));
    $hydraulicChecks = array_values(array_filter(is_array($record['hydraulicChecks'] ?? null) ? $record['hydraulicChecks'] : (is_array($record['hydraulic_checks'] ?? null) ? $record['hydraulic_checks'] : []), function ($item) {
        return is_array($item) && trim((string) ($item['equipment'] ?? '')) !== '';
    }));
    $frtDailyChecks = array_values(array_filter(is_array($record['frtDailyChecks'] ?? null) ? $record['frtDailyChecks'] : (is_array($record['frt_daily_checks'] ?? null) ? $record['frt_daily_checks'] : []), function ($item) {
        return is_array($item) && trim((string) ($item['equipment'] ?? '')) !== '';
    }));
    $frtOneOffChecks = array_values(array_filter(is_array($record['frtOneOffChecks'] ?? null) ? $record['frtOneOffChecks'] : (is_array($record['frt_one_off_checks'] ?? null) ? $record['frt_one_off_checks'] : []), function ($item) {
        return is_array($item) && trim((string) ($item['equipment'] ?? '')) !== '';
    }));
    $highAngleChecks = array_values(array_filter(is_array($record['highAngleChecks'] ?? null) ? $record['highAngleChecks'] : (is_array($record['high_angle_checks'] ?? null) ? $record['high_angle_checks'] : []), function ($item) {
        return is_array($item) && trim((string) ($item['equipment'] ?? '')) !== '';
    }));
    $scbaBackPlateChecks = array_values(array_filter(is_array($record['scbaBackPlateChecks'] ?? null) ? $record['scbaBackPlateChecks'] : (is_array($record['scba_back_plate_checks'] ?? null) ? $record['scba_back_plate_checks'] : []), function ($item) {
        return is_array($item) && trim((string) ($item['serialNo'] ?? $item['serial_no'] ?? '')) !== '';
    }));
    $scbaCylinderChecks = array_values(array_filter(is_array($record['scbaCylinderChecks'] ?? null) ? $record['scbaCylinderChecks'] : (is_array($record['scba_cylinder_checks'] ?? null) ? $record['scba_cylinder_checks'] : []), function ($item) {
        return is_array($item) && trim((string) ($item['serialNo'] ?? $item['serial_no'] ?? '')) !== '';
    }));
    $scbaFaceMaskChecks = array_values(array_filter(is_array($record['scbaFaceMaskChecks'] ?? null) ? $record['scbaFaceMaskChecks'] : (is_array($record['scba_face_mask_checks'] ?? null) ? $record['scba_face_mask_checks'] : []), function ($item) {
        return is_array($item) && trim((string) ($item['serialNo'] ?? $item['serial_no'] ?? '')) !== '';
    }));
    $scbaCustomSections = array_values(array_filter(is_array($record['scbaCustomSections'] ?? null) ? $record['scbaCustomSections'] : (is_array($record['scba_custom_sections'] ?? null) ? $record['scba_custom_sections'] : []), function ($item) {
        return is_array($item) && ($item['removed'] ?? false) !== true && trim((string) ($item['title'] ?? '')) !== '';
    }));
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
    $hseInspectedBy = trim((string) ($record['hseInspectedBy'] ?? $record['hse_inspected_by'] ?? ''));
    $hseInspectionDate = trim((string) ($record['hseInspectionDate'] ?? $record['hse_inspection_date'] ?? ''));
    $hseSelections = is_array($record['hseSelections'] ?? null) ? $record['hseSelections'] : (is_array($record['hse_selections'] ?? null) ? $record['hse_selections'] : []);
    $hseSelectionLabels = [
        'areaSatisfactory' => 'Area Satisfactory',
        'unsafeAct' => 'Unsafe Act',
        'unsafeCondition' => 'Unsafe Condition',
        'environmental' => 'Environmental',
    ];
    $hseDetailFields = [
        'areaSatisfactory' => ['label' => 'Area Condition Remarks', 'camel' => 'hseAreaConditionRemarks', 'snake' => 'hse_area_condition_remarks'],
        'unsafeAct' => ['label' => 'Unsafe Act Details', 'camel' => 'hseUnsafeActDetails', 'snake' => 'hse_unsafe_act_details'],
        'unsafeCondition' => ['label' => 'Unsafe Condition Details', 'camel' => 'hseUnsafeConditionDetails', 'snake' => 'hse_unsafe_condition_details'],
        'environmental' => ['label' => 'Environmental Details', 'camel' => 'hseEnvironmentalDetails', 'snake' => 'hse_environmental_details'],
    ];
    $hseSeverity = trim((string) ($record['hseSeverity'] ?? $record['hse_severity'] ?? ''));
    $hseOptionalFields = [
        ['label' => 'Immediate Action', 'camel' => 'hseImmediateAction', 'snake' => 'hse_immediate_action'],
        ['label' => 'Corrective Action', 'camel' => 'hseCorrectiveAction', 'snake' => 'hse_corrective_action'],
        ['label' => 'Responsible Person', 'camel' => 'hseResponsiblePerson', 'snake' => 'hse_responsible_person'],
        ['label' => 'Target Date', 'camel' => 'hseTargetDate', 'snake' => 'hse_target_date'],
        ['label' => 'General HSE Remarks', 'camel' => 'hseRemarks', 'snake' => 'hse_remarks'],
    ];
    $hasHseObservation = $isHseInspection && (count($hseSelections) > 0 || $hseInspectedBy !== '' || $hseInspectionDate !== '');
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
    $photos = is_array($record['photos'] ?? null) ? $record['photos'] : [];
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

    $photos = array_values(array_filter($photos, function ($photo) {
        if (!is_array($photo)) return false;
        $url = trim((string) ($photo['url'] ?? ''));
        if ($url === '') return false;
        return (bool) preg_match('/^data:image\/[a-z0-9.+-]+;base64,/i', $url);
    }));
    $filterInlinePhotos = function ($items) {
        $rows = is_array($items) ? $items : [];
        return array_values(array_filter($rows, function ($photo) {
            if (!is_array($photo)) return false;
            $url = trim((string) ($photo['url'] ?? ''));
            if ($url === '') return false;
            return (bool) preg_match('/^data:image\/[a-z0-9.+-]+;base64,/i', $url);
        }));
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
    $formatPhotoDescription = function ($photo) {
        $description = trim((string) ($photo['description'] ?? ''));
        if ($description === '') {
            $description = 'Image description not provided by user';
        }
        $description = preg_replace('/\s+/u', ' ', trim($description));
        if ($description !== '') {
            $descriptionLower = mb_strtolower($description, 'UTF-8');
            $description = mb_strtoupper(mb_substr($descriptionLower, 0, 1, 'UTF-8'), 'UTF-8')
                . mb_substr($descriptionLower, 1, null, 'UTF-8');
        }
        if (!preg_match('/[.!?]$/u', $description)) {
            $description .= '.';
        }
        return $description;
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
    $photoColumns = count($photos) > 1 ? 2 : 1;

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

    $generatedAt = now()->format('d M Y, H:i');
@endphp

<div class="footer">
    <div class="footer-left">Inspection Report - {{ $displayId }}</div>
    <div class="footer-right">Generated {{ $generatedAt }}</div>
</div>

<div class="header">
    <div class="header-left">
        <div class="report-title">Inspection Report</div>
        <div class="report-subtitle">By Vale Mineral Malaysia Emergency Control Center (VMECC)</div>
    </div>
    <div class="header-right">
        <div class="report-id">{{ $displayId }}</div>
        <div><span class="status-badge">{{ $status }}</span></div>
    </div>
</div>

<div class="card">
    <div class="card-head">Inspection Overview</div>
    <div class="card-body">
        <div class="meta-grid meta-grid-4">
            <div class="meta-cell">
                <div class="meta-label">Inspection Type</div>
                <div class="meta-value">{{ $inspectionType ?: '--' }}</div>
            </div>
            <div class="meta-cell">
                <div class="meta-label">Location</div>
                <div class="meta-value">{{ $location ?: '--' }}</div>
            </div>
            <div class="meta-cell">
                <div class="meta-label">Submitted</div>
                <div class="meta-value">{{ $submittedAt ?: '--' }}</div>
            </div>
            <div class="meta-cell">
                <div class="meta-label">Submitted By</div>
                <div class="meta-value">{{ $submittedBy ?: '--' }}</div>
                @if ($submittedByRole !== '')
                    <div class="person-meta">{{ $submittedByRole }}</div>
                @endif
            </div>
        </div>
    </div>
</div>

@if ($shouldRenderDescriptionCard)
<div class="card">
    <div class="card-head">Inspection Description</div>
    <div class="card-body">
        <div class="text-block-label">Summary</div>
        <div class="text-block-value">{{ trim($description) !== '' ? $description : 'No description provided.' }}</div>
    </div>
</div>
@endif

@if ($shouldRenderChecklistCard)
<div class="card">
    <div class="card-head">Checklist</div>
    <div class="card-body">
        <ul class="checklist-list">
            @foreach ($checklist as $item)
                <li>{{ trim((string) ($item['label'] ?? '')) }}</li>
            @endforeach
        </ul>
    </div>
</div>
@endif

@if ($hasHseObservation)
<div class="card">
    <div class="card-head">HSE Observation</div>
    <div class="card-body">
        <div class="meta-grid meta-grid-4" style="margin-bottom: 8px;">
            <div class="meta-cell">
                <div class="meta-label">Inspected By</div>
                <div class="meta-value">{{ $hseInspectedBy !== '' ? $hseInspectedBy : '--' }}</div>
                @if ($inspectedByRole !== '')
                    <div class="person-meta">{{ $inspectedByRole }}</div>
                @endif
            </div>
            <div class="meta-cell">
                <div class="meta-label">Inspection Date</div>
                <div class="meta-value">{{ $hseInspectionDate !== '' ? $hseInspectionDate : '--' }}</div>
            </div>
            <div class="meta-cell">
                <div class="meta-label">Severity</div>
                <div class="meta-value">{{ $hseSeverity !== '' ? $hseSeverity : '--' }}</div>
            </div>
            <div class="meta-cell">
                <div class="meta-label">Outcome</div>
                <div class="meta-value">
                    @if (count($hseSelections) > 0)
                        @foreach ($hseSelections as $selection)
                            <span class="pill">{{ $hseSelectionLabels[$selection] ?? $selection }}</span>
                        @endforeach
                    @else
                        --
                    @endif
                </div>
            </div>
        </div>

        @foreach ($hseSelections as $selection)
            @php
                $field = $hseDetailFields[$selection] ?? null;
                $value = $field ? trim((string) ($record[$field['camel']] ?? $record[$field['snake']] ?? '')) : '';
            @endphp
            @if ($field && $value !== '')
                <div class="divider"></div>
                <div class="text-block-label">{{ $field['label'] }}</div>
                <div class="text-block-value">{{ $value }}</div>
            @endif
        @endforeach

        @foreach ($hseOptionalFields as $field)
            @php
                $value = trim((string) ($record[$field['camel']] ?? $record[$field['snake']] ?? ''));
            @endphp
            @if ($value !== '')
                <div class="divider"></div>
                <div class="text-block-label">{{ $field['label'] }}</div>
                <div class="text-block-value">{{ $value }}</div>
            @endif
        @endforeach
    </div>
</div>
@endif

@if ($isFireExtinguisherInspection && count($fireExtinguisherChecks) > 0)
<div class="card">
    <div class="card-head">Fire Extinguisher Checks</div>
    <div class="card-body">
        <div class="meta-grid meta-grid-4" style="margin-bottom: 8px;">
            <div class="meta-cell">
                <div class="meta-label">Inspected By</div>
                <div class="meta-value">{{ $fireExtinguisherInspectedBy !== '' ? $fireExtinguisherInspectedBy : '--' }}</div>
                @if ($inspectedByRole !== '')
                    <div class="person-meta">{{ $inspectedByRole }}</div>
                @endif
            </div>
            <div class="meta-cell">
                <div class="meta-label">Inspection Date</div>
                <div class="meta-value">{{ $fireExtinguisherInspectionDate !== '' ? $fireExtinguisherInspectionDate : '--' }}</div>
            </div>
        </div>
        <table class="hydraulic-checks">
            <thead>
                <tr>
                    <th style="width: 16%;">ID / Barcode</th>
                    <th style="width: 16%;">Location</th>
                    <th style="width: 10%;">Type</th>
                    <th style="width: 12%;">Validity</th>
                    <th style="width: 10%;">Physical</th>
                    <th style="width: 10%;">Signage</th>
                    <th style="width: 10%;">Key</th>
                    <th style="width: 10%;">Glass</th>
                    <th style="width: 10%;">Operational</th>
                    <th style="width: 16%;">Remarks</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($fireExtinguisherChecks as $check)
                    <tr>
                        <td>
                            {{ trim((string) ($check['idLocNo'] ?? $check['id_loc_no'] ?? '')) ?: '--' }}
                            <div style="margin-top: 3px; color: #6b7280; font-size: 10px; line-height: 1.35;">
                                {{ trim((string) ($check['barcodeNo'] ?? $check['barcode_no'] ?? '')) ?: '--' }}
                            </div>
                        </td>
                        <td>{{ trim((string) ($check['subLocation'] ?? $check['sub_location'] ?? $check['mainLocation'] ?? $check['main_location'] ?? '')) ?: '--' }}</td>
                        <td>{{ trim((string) ($check['feType'] ?? $check['fe_type'] ?? '')) ?: '--' }}</td>
                        <td>{{ trim((string) ($check['certificationValidity'] ?? $check['certification_validity'] ?? $check['certificationValidityRaw'] ?? $check['certification_validity_raw'] ?? '')) ?: '--' }}</td>
                        <td>{{ trim((string) ($check['physicalCondition'] ?? $check['physical_condition'] ?? '')) ?: '--' }}</td>
                        <td>{{ trim((string) ($check['signageCondition'] ?? $check['signage_condition'] ?? '')) ?: '--' }}</td>
                        <td>{{ trim((string) ($check['boxKeyAvailability'] ?? $check['box_key_availability'] ?? '')) ?: '--' }}</td>
                        <td>{{ trim((string) ($check['boxGlassAvailability'] ?? $check['box_glass_availability'] ?? '')) ?: '--' }}</td>
                        <td>{{ trim((string) ($check['operationalCondition'] ?? $check['operational_condition'] ?? '')) ?: '--' }}</td>
                        <td>{{ trim((string) ($check['remarks'] ?? '')) ?: '--' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @php $compactBlocks = []; @endphp
        @foreach ($fireExtinguisherChecks as $check)
            @php
                $feName = trim((string) ($check['idLocNo'] ?? $check['id_loc_no'] ?? $check['barcodeNo'] ?? $check['barcode_no'] ?? '')) ?: 'Fire extinguisher';
                $fireFields = [
                    ['status' => 'physicalCondition', 'status_snake' => 'physical_condition', 'label' => 'FE Physical Condition', 'remarks' => 'physicalConditionRemarks', 'remarks_snake' => 'physical_condition_remarks', 'photos' => 'physicalConditionPhotos', 'photos_snake' => 'physical_condition_photos'],
                    ['status' => 'signageCondition', 'status_snake' => 'signage_condition', 'label' => 'FE Signage Condition', 'remarks' => 'signageConditionRemarks', 'remarks_snake' => 'signage_condition_remarks', 'photos' => 'signageConditionPhotos', 'photos_snake' => 'signage_condition_photos'],
                    ['status' => 'boxKeyAvailability', 'status_snake' => 'box_key_availability', 'label' => 'FE Box Key Availability', 'remarks' => 'boxKeyAvailabilityRemarks', 'remarks_snake' => 'box_key_availability_remarks', 'photos' => 'boxKeyAvailabilityPhotos', 'photos_snake' => 'box_key_availability_photos'],
                    ['status' => 'boxGlassAvailability', 'status_snake' => 'box_glass_availability', 'label' => 'FE Box Glass Availability', 'remarks' => 'boxGlassAvailabilityRemarks', 'remarks_snake' => 'box_glass_availability_remarks', 'photos' => 'boxGlassAvailabilityPhotos', 'photos_snake' => 'box_glass_availability_photos'],
                    ['status' => 'operationalCondition', 'status_snake' => 'operational_condition', 'label' => 'Operational Condition', 'remarks' => 'operationalConditionRemarks', 'remarks_snake' => 'operational_condition_remarks', 'photos' => 'operationalConditionPhotos', 'photos_snake' => 'operational_condition_photos'],
                ];
            @endphp
            @foreach ($fireFields as $field)
                @php
                    $statusValue = strtolower(trim((string) ($check[$field['status']] ?? $check[$field['status_snake']] ?? '')));
                    $remarksValue = trim((string) ($check[$field['remarks']] ?? $check[$field['remarks_snake']] ?? ''));
                    $defectPhotos = $filterInlinePhotos($check[$field['photos']] ?? $check[$field['photos_snake']] ?? []);
                    $defectPhotoColumns = count($defectPhotos) > 1 ? 2 : 1;
                    $defectTitle = 'Defect Evidence: '.$feName.' - '.$field['label'];
                    $compactDefectOnly = in_array($statusValue, ['not good', 'no', 'not operational'], true)
                        && count($defectPhotos) === 0
                        && $isCompactText($remarksValue);
                    if ($compactDefectOnly) {
                        $compactBlocks[] = $compactBlock($defectTitle, 'Defect remarks', $remarksValue);
                    }
                @endphp
                @if (in_array($statusValue, ['not good', 'no', 'not operational'], true) && ! $compactDefectOnly && ($remarksValue !== '' || count($defectPhotos) > 0))
                    <div class="text-block-label" style="margin-top: 10px;">
                        {{ $defectTitle }}
                    </div>
                    @if ($remarksValue !== '')
                        <div class="text-block-value">{{ $remarksValue }}</div>
                    @endif
                    @if (count($defectPhotos) > 0)
                        <table class="photo-grid">
                            @foreach (array_chunk($defectPhotos, $defectPhotoColumns) as $photoRow)
                                <tr>
                                    @foreach ($photoRow as $photo)
                                        @php $description = $formatPhotoDescription($photo); @endphp
                                        <td style="width: {{ $defectPhotoColumns === 1 ? '100%' : '50%' }};">
                                            <div class="photo-card">
                                                <div class="photo-figure">
                                                    <div class="photo-image-wrap">
                                                        <img class="photo-image" src="{{ trim((string) ($photo['url'] ?? '')) }}" alt="Fire extinguisher defect photo">
                                                    </div>
                                                    <div class="photo-caption">
                                                        <div class="photo-description">{{ $description }}</div>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                    @endforeach
                                    @if ($defectPhotoColumns === 2 && count($photoRow) === 1)
                                        <td></td>
                                    @endif
                                </tr>
                            @endforeach
                        </table>
                    @endif
                @endif
            @endforeach
        @endforeach
        {!! $renderCompactBlocks($compactBlocks) !!}
    </div>
</div>
@endif

@if ($isHydraulicInspection && count($hydraulicChecks) > 0)
<div class="card">
    <div class="card-head">Hydraulic Equipment Checks</div>
    <div class="card-body">
        <table class="hydraulic-checks">
            <thead>
                <tr>
                    <th style="width: 22%;">Equipment</th>
                    <th style="width: 10%;">Location</th>
                    <th style="width: 12%;">Physical</th>
                    <th style="width: 12%;">Mechanical</th>
                    <th style="width: 12%;">Leakage</th>
                    <th style="width: 12%;">Function</th>
                    <th style="width: 20%;">Remarks</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($hydraulicChecks as $check)
                    @php
                        $equipmentDescription = trim((string) ($check['equipmentDescription'] ?? $check['equipment_description'] ?? ''));
                    @endphp
                    <tr>
                        <td>
                            {{ trim((string) ($check['equipment'] ?? '')) ?: '--' }}
                            @if (($check['equipmentSource'] ?? $check['equipment_source'] ?? '') === 'custom' || ($check['isCustomEquipment'] ?? $check['is_custom_equipment'] ?? false))
                                <span class="pill">Custom</span>
                            @endif
                            @if ($equipmentDescription !== '')
                                <div style="margin-top: 3px; color: #6b7280; font-size: 10px; line-height: 1.35;">{{ $equipmentDescription }}</div>
                            @endif
                        </td>
                        <td>{{ trim((string) ($check['location'] ?? '')) ?: '--' }}</td>
                        <td>{{ trim((string) ($check['physicalCondition'] ?? $check['physical_condition'] ?? '')) ?: '--' }}</td>
                        <td>{{ trim((string) ($check['mechanicalCondition'] ?? $check['mechanical_condition'] ?? '')) ?: '--' }}</td>
                        <td>{{ trim((string) ($check['noLeakage'] ?? $check['no_leakage'] ?? '')) ?: '--' }}</td>
                        <td>{{ trim((string) ($check['functionTest'] ?? $check['function_test'] ?? '')) ?: '--' }}</td>
                        <td>{{ trim((string) ($check['remarks'] ?? '')) ?: '--' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @php $compactBlocks = []; @endphp
        @foreach ($hydraulicChecks as $check)
            @php
                $equipmentName = trim((string) ($check['equipment'] ?? '')) ?: 'Hydraulic equipment';
                $equipmentPhotos = $filterInlinePhotos($check['photos'] ?? []);
                $equipmentPhotoColumns = count($equipmentPhotos) > 1 ? 2 : 1;
            @endphp
            @foreach ($hydraulicCheckFields as $field)
                @php
                    $statusValue = trim((string) ($check[$field['status']] ?? $check[$field['status_snake']] ?? ''));
                    $defectRemarks = trim((string) ($check[$field['remarks']] ?? $check[$field['remarks_snake']] ?? ''));
                    $defectPhotos = $filterInlinePhotos($check[$field['photos']] ?? $check[$field['photos_snake']] ?? []);
                    $defectPhotoColumns = count($defectPhotos) > 1 ? 2 : 1;
                    $defectTitle = 'Defect Evidence: '.$equipmentName.' - '.$field['label'];
                    $naTitle = 'N/A Reason: '.$equipmentName.' - '.$field['label'];
                    $compactDefectOnly = strcasecmp($statusValue, 'Defect') === 0 && count($defectPhotos) === 0 && $isCompactText($defectRemarks);
                    $compactNaOnly = strcasecmp($statusValue, 'N/A') === 0 && $isCompactText($defectRemarks);
                    if ($compactDefectOnly) {
                        $compactBlocks[] = $compactBlock($defectTitle, 'Defect remarks', $defectRemarks);
                    }
                    if ($compactNaOnly) {
                        $compactBlocks[] = $compactBlock($naTitle, 'Reason', $defectRemarks);
                    }
                @endphp
                @if (strcasecmp($statusValue, 'Defect') === 0 && ! $compactDefectOnly && ($defectRemarks !== '' || count($defectPhotos) > 0))
                    <div class="text-block-label" style="margin-top: 10px;">
                        {{ $defectTitle }}
                    </div>
                    @if ($defectRemarks !== '')
                        <div class="text-block-value">{{ $defectRemarks }}</div>
                    @endif
                    @if (count($defectPhotos) > 0)
                        <table class="photo-grid">
                            @foreach (array_chunk($defectPhotos, $defectPhotoColumns) as $photoRow)
                                <tr>
                                    @foreach ($photoRow as $photo)
                                        @php
                                            $description = trim((string) ($photo['description'] ?? ''));
                                            if ($description === '') {
                                                $description = 'Image description not provided by user';
                                            }
                                            $description = preg_replace('/\s+/u', ' ', trim($description));
                                            if ($description !== '') {
                                                $descriptionLower = mb_strtolower($description, 'UTF-8');
                                                $description = mb_strtoupper(mb_substr($descriptionLower, 0, 1, 'UTF-8'), 'UTF-8')
                                                    . mb_substr($descriptionLower, 1, null, 'UTF-8');
                                            }
                                            if (!preg_match('/[.!?]$/u', $description)) {
                                                $description .= '.';
                                            }
                                        @endphp
                                        <td style="width: {{ $defectPhotoColumns === 1 ? '100%' : '50%' }};">
                                            <div class="photo-card">
                                                <div class="photo-figure">
                                                    <div class="photo-image-wrap">
                                                        <img class="photo-image" src="{{ trim((string) ($photo['url'] ?? '')) }}" alt="Hydraulic defect photo">
                                                    </div>
                                                    <div class="photo-caption">
                                                        <div class="photo-description">{{ $description }}</div>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                    @endforeach
                                    @if ($defectPhotoColumns === 2 && count($photoRow) === 1)
                                        <td></td>
                                    @endif
                                </tr>
                            @endforeach
                        </table>
                    @endif
                @endif
                @if (strcasecmp($statusValue, 'N/A') === 0 && ! $compactNaOnly && $defectRemarks !== '')
                    <div class="text-block-label" style="margin-top: 10px;">
                        {{ $naTitle }}
                    </div>
                    <div class="text-block-value">{{ $defectRemarks }}</div>
                @endif
            @endforeach
            @if (count($equipmentPhotos) > 0)
                <div class="text-block-label" style="margin-top: 10px;">
                    Equipment Evidence: {{ $equipmentName }}
                </div>
                <table class="photo-grid">
                    @foreach (array_chunk($equipmentPhotos, $equipmentPhotoColumns) as $photoRow)
                        <tr>
                            @foreach ($photoRow as $photo)
                                @php
                                    $description = trim((string) ($photo['description'] ?? ''));
                                    if ($description === '') {
                                        $description = 'Image description not provided by user';
                                    }
                                    $description = preg_replace('/\s+/u', ' ', trim($description));
                                    if ($description !== '') {
                                        $descriptionLower = mb_strtolower($description, 'UTF-8');
                                        $description = mb_strtoupper(mb_substr($descriptionLower, 0, 1, 'UTF-8'), 'UTF-8')
                                            . mb_substr($descriptionLower, 1, null, 'UTF-8');
                                    }
                                    if (!preg_match('/[.!?]$/u', $description)) {
                                        $description .= '.';
                                    }
                                @endphp
                                <td style="width: {{ $equipmentPhotoColumns === 1 ? '100%' : '50%' }};">
                                    <div class="photo-card">
                                        <div class="photo-figure">
                                            <div class="photo-image-wrap">
                                                <img class="photo-image" src="{{ trim((string) ($photo['url'] ?? '')) }}" alt="Hydraulic equipment photo">
                                            </div>
                                            <div class="photo-caption">
                                                <div class="photo-description">{{ $description }}</div>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            @endforeach
                            @if ($equipmentPhotoColumns === 2 && count($photoRow) === 1)
                                <td></td>
                            @endif
                        </tr>
                    @endforeach
                </table>
            @endif
        @endforeach
        {!! $renderCompactBlocks($compactBlocks) !!}
    </div>
</div>
@endif

@if ($isFrtInspection && $hasFrtChecks)
<div class="card">
    <div class="card-head">Fire Truck Daily Readiness</div>
    <div class="card-body">
        <div class="meta-grid meta-grid-4" style="margin-bottom: 8px;">
            <div class="meta-cell">
                <div class="meta-label">Inspected By</div>
                <div class="meta-value">{{ $frtInspectedBy !== '' ? $frtInspectedBy : '--' }}</div>
                @if ($inspectedByRole !== '')
                    <div class="person-meta">{{ $inspectedByRole }}</div>
                @endif
            </div>
            <div class="meta-cell">
                <div class="meta-label">Inspection Date</div>
                <div class="meta-value">{{ $frtInspectionDate !== '' ? $frtInspectionDate : '--' }}</div>
            </div>
            <div class="meta-cell">
                <div class="meta-label">Plate No</div>
                <div class="meta-value">{{ $frtTruckPlate !== '' ? $frtTruckPlate : '--' }}</div>
            </div>
            <div class="meta-cell">
                <div class="meta-label">Truck</div>
                <div class="meta-value">{{ trim((string) ($frtTruckReference['name'] ?? $frtTruckReference['truckName'] ?? $frtTruckReference['truck_name'] ?? '')) ?: '--' }}</div>
            </div>
        </div>
        <div class="meta-grid meta-grid-4" style="margin-bottom: 8px;">
            <div class="meta-cell">
                <div class="meta-label">Truck Details</div>
                <div class="meta-value">Daily readiness</div>
            </div>
            <div class="meta-cell">
                <div class="meta-label">Road Tax Expiry</div>
                <div class="meta-value">{{ trim((string) ($frtTruckReference['roadTaxExpiry'] ?? $frtTruckReference['road_tax_expiry'] ?? '')) ?: '--' }}</div>
            </div>
            <div class="meta-cell">
                <div class="meta-label">Insurance Expiry</div>
                <div class="meta-value">{{ trim((string) ($frtTruckReference['insuranceExpiry'] ?? $frtTruckReference['insurance_expiry'] ?? '')) ?: '--' }}</div>
            </div>
            <div class="meta-cell">
                <div class="meta-label">Puspakom Expiry</div>
                <div class="meta-value">{{ trim((string) ($frtTruckReference['puspakomExpiry'] ?? $frtTruckReference['puspakom_expiry'] ?? '')) ?: '--' }}</div>
            </div>
        </div>

        @if (count($frtDailyChecks) > 0)
            <div class="text-block-label" style="margin: 0 0 4px; font-weight: 700; color: #374151;">
                Daily Readiness Roster
            </div>
            @foreach ($frtDailyGroups as $group)
                <div class="text-block-label" style="margin: {{ $loop->first ? '0' : '10px' }} 0 4px; font-weight: 700; color: #374151;">
                    {{ $group['title'] }}
                </div>
                <table class="hydraulic-checks">
                    <thead>
                        <tr>
                            <th style="width: 8%;">Row</th>
                            <th style="width: 31%;">Equipment</th>
                            <th style="width: 10%;">Qty</th>
                            <th style="width: 12%;">Kind</th>
                            <th style="width: 12%;">Status</th>
                            <th style="width: 12%;">Reading</th>
                            <th style="width: 15%;">Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($group['rows'] as $check)
                            @php
                                $rowKind = trim((string) ($check['rowKind'] ?? $check['row_kind'] ?? 'status')) ?: 'status';
                                $rowStatus = trim((string) ($check['status'] ?? ''));
                                $readingValue = trim((string) ($check['readingValue'] ?? $check['reading_value'] ?? ''));
                            @endphp
                            <tr>
                                <td>{{ trim((string) ($check['rowNumber'] ?? $check['row_number'] ?? '')) ?: '--' }}</td>
                                <td>{{ trim((string) ($check['equipment'] ?? '')) ?: '--' }}</td>
                                <td>{{ trim((string) ($check['quantity'] ?? '')) ?: '--' }}</td>
                                <td>{{ ucfirst($rowKind) }}</td>
                                <td>{{ $rowKind === 'reading' ? '--' : ($rowStatus !== '' ? $rowStatus : '--') }}</td>
                                <td>{{ $rowKind === 'reading' ? ($readingValue !== '' ? $readingValue : '--') : '--' }}</td>
                                <td>{{ trim((string) ($check['remarks'] ?? '')) ?: '--' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @php $compactBlocks = []; @endphp
                @foreach ($group['rows'] as $check)
                    @php
                        $rowStatus = trim((string) ($check['status'] ?? ''));
                        $issuePhotos = strcasecmp($rowStatus, 'Issue') === 0 ? $filterInlinePhotos($check['photos'] ?? []) : [];
                        $issuePhotoColumns = count($issuePhotos) > 1 ? 2 : 1;
                        $additionalNotes = trim((string) ($check['additionalNotes'] ?? $check['additional_notes'] ?? ''));
                        $additionalPhotos = $filterInlinePhotos($check['additionalPhotos'] ?? $check['additional_photos'] ?? []);
                        $additionalPhotoColumns = count($additionalPhotos) > 1 ? 2 : 1;
                        $additionalTitle = 'Additional Info - Row '.(trim((string) ($check['rowNumber'] ?? $check['row_number'] ?? '')) ?: '--').': '.(trim((string) ($check['equipment'] ?? '')) ?: '--');
                        $compactAdditionalOnly = count($additionalPhotos) === 0 && $isCompactText($additionalNotes);
                        if ($compactAdditionalOnly) {
                            $compactBlocks[] = $compactBlock($additionalTitle, 'General equipment remarks', $additionalNotes);
                        }
                    @endphp
                    @if (count($issuePhotos) > 0)
                        <div class="text-block-label" style="margin-top: 6px; font-weight: 700; color: #374151;">
                            Issue Evidence - Row {{ trim((string) ($check['rowNumber'] ?? $check['row_number'] ?? '')) ?: '--' }}: {{ trim((string) ($check['equipment'] ?? '')) ?: '--' }}
                        </div>
                        <table class="photo-grid">
                            @foreach (array_chunk($issuePhotos, $issuePhotoColumns) as $photoRow)
                                <tr>
                                    @foreach ($photoRow as $photo)
                                        <td style="width: {{ $issuePhotoColumns === 1 ? '100%' : '50%' }};">
                                            <div class="photo-card">
                                                <div class="photo-image-wrap">
                                                    <img class="photo-image" src="{{ trim((string) ($photo['url'] ?? '')) }}" alt="FRT issue photo">
                                                </div>
                                                @if (trim((string) ($photo['description'] ?? '')) !== '')
                                                    <div class="photo-caption">
                                                        <div class="photo-description">{{ trim((string) ($photo['description'] ?? '')) }}</div>
                                                    </div>
                                                @endif
                                            </div>
                                        </td>
                                    @endforeach
                                    @if ($issuePhotoColumns === 2 && count($photoRow) === 1)
                                        <td></td>
                                    @endif
                                </tr>
                            @endforeach
                        </table>
                    @endif
                    @if (! $compactAdditionalOnly && ($additionalNotes !== '' || count($additionalPhotos) > 0))
                        <div class="text-block-label" style="margin-top: 6px; font-weight: 700; color: #374151;">
                            {{ $additionalTitle }}
                        </div>
                        @if ($additionalNotes !== '')
                            <div class="text-block-label">General equipment remarks</div>
                            <div class="text-block-value">{{ $additionalNotes }}</div>
                        @endif
                        @if (count($additionalPhotos) > 0)
                            <table class="photo-grid">
                                @foreach (array_chunk($additionalPhotos, $additionalPhotoColumns) as $photoRow)
                                    <tr>
                                        @foreach ($photoRow as $photo)
                                            @php $description = $formatPhotoDescription($photo); @endphp
                                            <td style="width: {{ $additionalPhotoColumns === 1 ? '100%' : '50%' }};">
                                                <div class="photo-card">
                                                    <div class="photo-figure">
                                                        <div class="photo-image-wrap">
                                                            <img class="photo-image" src="{{ trim((string) ($photo['url'] ?? '')) }}" alt="FRT additional photo">
                                                        </div>
                                                        <div class="photo-caption">
                                                            <div class="photo-description">{{ $description }}</div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                        @endforeach
                                        @if ($additionalPhotoColumns === 2 && count($photoRow) === 1)
                                            <td></td>
                                        @endif
                                    </tr>
                                @endforeach
                            </table>
                        @endif
                    @endif
                @endforeach
                {!! $renderCompactBlocks($compactBlocks) !!}
            @endforeach
            @if ($frtDailyRemarks !== '')
                <div class="text-block-label" style="margin-top: 10px;">Daily Remarks</div>
                <div class="text-block-value">{{ $frtDailyRemarks }}</div>
            @endif
        @endif

        @if (count($frtOneOffChecks) > 0)
            <div class="text-block-label" style="margin: {{ count($frtDailyChecks) > 0 ? '10px' : '0' }} 0 4px; font-weight: 700; color: #374151;">
                One-Off Readiness Checklist
            </div>
            @foreach ($frtOneOffGroups as $group)
                <div class="text-block-label" style="margin: {{ $loop->first ? '0' : '10px' }} 0 4px; font-weight: 700; color: #374151;">
                    {{ $group['title'] }}
                </div>
                <table class="hydraulic-checks">
                    <thead>
                        <tr>
                            <th style="width: 8%;">Row</th>
                            <th style="width: 47%;">Equipment</th>
                            <th style="width: 15%;">Condition</th>
                            <th style="width: 30%;">Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($group['rows'] as $check)
                            <tr>
                                <td>{{ trim((string) ($check['rowNumber'] ?? $check['row_number'] ?? '')) ?: '--' }}</td>
                                <td>{{ trim((string) ($check['equipment'] ?? '')) ?: '--' }}</td>
                                <td>{{ trim((string) ($check['condition'] ?? '')) ?: '--' }}</td>
                                <td>{{ trim((string) ($check['remarks'] ?? '')) ?: '--' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @php $compactBlocks = []; @endphp
                @foreach ($group['rows'] as $check)
                    @php
                        $condition = trim((string) ($check['condition'] ?? ''));
                        $issuePhotos = strcasecmp($condition, 'Not Good') === 0 ? $filterInlinePhotos($check['photos'] ?? []) : [];
                        $issuePhotoColumns = count($issuePhotos) > 1 ? 2 : 1;
                        $additionalNotes = trim((string) ($check['additionalNotes'] ?? $check['additional_notes'] ?? ''));
                        $additionalPhotos = $filterInlinePhotos($check['additionalPhotos'] ?? $check['additional_photos'] ?? []);
                        $additionalPhotoColumns = count($additionalPhotos) > 1 ? 2 : 1;
                        $additionalTitle = 'Additional Info - Row '.(trim((string) ($check['rowNumber'] ?? $check['row_number'] ?? '')) ?: '--').': '.(trim((string) ($check['equipment'] ?? '')) ?: '--');
                        $compactAdditionalOnly = count($additionalPhotos) === 0 && $isCompactText($additionalNotes);
                        if ($compactAdditionalOnly) {
                            $compactBlocks[] = $compactBlock($additionalTitle, 'General equipment remarks', $additionalNotes);
                        }
                    @endphp
                    @if (count($issuePhotos) > 0)
                        <div class="text-block-label" style="margin-top: 6px; font-weight: 700; color: #374151;">
                            Issue Evidence - Row {{ trim((string) ($check['rowNumber'] ?? $check['row_number'] ?? '')) ?: '--' }}: {{ trim((string) ($check['equipment'] ?? '')) ?: '--' }}
                        </div>
                        <table class="photo-grid">
                            @foreach (array_chunk($issuePhotos, $issuePhotoColumns) as $photoRow)
                                <tr>
                                    @foreach ($photoRow as $photo)
                                        <td style="width: {{ $issuePhotoColumns === 1 ? '100%' : '50%' }};">
                                            <div class="photo-card">
                                                <div class="photo-image-wrap">
                                                    <img class="photo-image" src="{{ trim((string) ($photo['url'] ?? '')) }}" alt="FRT one-off issue photo">
                                                </div>
                                                @if (trim((string) ($photo['description'] ?? '')) !== '')
                                                    <div class="photo-caption">
                                                        <div class="photo-description">{{ trim((string) ($photo['description'] ?? '')) }}</div>
                                                    </div>
                                                @endif
                                            </div>
                                        </td>
                                    @endforeach
                                    @if ($issuePhotoColumns === 2 && count($photoRow) === 1)
                                        <td></td>
                                    @endif
                                </tr>
                            @endforeach
                        </table>
                    @endif
                    @if (! $compactAdditionalOnly && ($additionalNotes !== '' || count($additionalPhotos) > 0))
                        <div class="text-block-label" style="margin-top: 6px; font-weight: 700; color: #374151;">
                            {{ $additionalTitle }}
                        </div>
                        @if ($additionalNotes !== '')
                            <div class="text-block-label">General equipment remarks</div>
                            <div class="text-block-value">{{ $additionalNotes }}</div>
                        @endif
                        @if (count($additionalPhotos) > 0)
                            <table class="photo-grid">
                                @foreach (array_chunk($additionalPhotos, $additionalPhotoColumns) as $photoRow)
                                    <tr>
                                        @foreach ($photoRow as $photo)
                                            @php $description = $formatPhotoDescription($photo); @endphp
                                            <td style="width: {{ $additionalPhotoColumns === 1 ? '100%' : '50%' }};">
                                                <div class="photo-card">
                                                    <div class="photo-figure">
                                                        <div class="photo-image-wrap">
                                                            <img class="photo-image" src="{{ trim((string) ($photo['url'] ?? '')) }}" alt="FRT one-off additional photo">
                                                        </div>
                                                        <div class="photo-caption">
                                                            <div class="photo-description">{{ $description }}</div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                        @endforeach
                                        @if ($additionalPhotoColumns === 2 && count($photoRow) === 1)
                                            <td></td>
                                        @endif
                                    </tr>
                                @endforeach
                            </table>
                        @endif
                    @endif
                @endforeach
                {!! $renderCompactBlocks($compactBlocks) !!}
            @endforeach
            @if ($frtOneOffRemarks !== '')
                <div class="text-block-label" style="margin-top: 10px;">One-off Remarks</div>
                <div class="text-block-value">{{ $frtOneOffRemarks }}</div>
            @endif
        @endif
    </div>
</div>
@endif

@if ($isScbaInspection && $hasScbaChecks)
<div class="card">
    <div class="card-head">SCBA Checks</div>
    <div class="card-body">
        <div class="meta-grid" style="margin-bottom: 8px;">
            <div class="meta-cell" style="width: 50%;">
                <div class="meta-label">Inspected By</div>
                <div class="meta-value">{{ $scbaInspectedBy !== '' ? $scbaInspectedBy : '--' }}</div>
                @if ($inspectedByRole !== '')
                    <div class="person-meta">{{ $inspectedByRole }}</div>
                @endif
            </div>
            <div class="meta-cell" style="width: 50%;">
                <div class="meta-label">Inspection Date</div>
                <div class="meta-value">{{ $scbaInspectionDate !== '' ? $scbaInspectionDate : '--' }}</div>
            </div>
        </div>
        @foreach ($scbaSections as $section)
            @if (count($section['rows']) > 0)
                <div class="text-block-label" style="margin: {{ $loop->first ? '0' : '10px' }} 0 4px; font-weight: 700; color: #374151;">
                    {{ $section['title'] }}
                </div>
                <table class="hydraulic-checks">
                    <thead>
                        <tr>
                            @foreach ($section['columns'] as $column)
                                <th>{{ $column['label'] }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($section['rows'] as $check)
                            <tr>
                                @foreach ($section['columns'] as $column)
                                    @php
                                        $value = trim((string) ($check[$column['camel']] ?? $check[$column['snake']] ?? ''));
                                    @endphp
                                    <td>{{ $value !== '' ? $value : '--' }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @php $compactBlocks = []; @endphp
                @foreach ($section['rows'] as $check)
                    @php
                        $brand = trim((string) ($check['brand'] ?? ''));
                        $serialNo = trim((string) ($check['serialNo'] ?? $check['serial_no'] ?? ''));
                        $equipmentName = trim($brand.' '.$serialNo) ?: 'SCBA item';
                        $generalRemarks = trim((string) ($check['remarks'] ?? $check['remark'] ?? ''));
                        $additionalPhotos = $filterInlinePhotos($check['photos'] ?? []);
                        $additionalPhotoColumns = count($additionalPhotos) > 1 ? 2 : 1;
                        if (count($additionalPhotos) === 0 && $isCompactText($generalRemarks)) {
                            $compactBlocks[] = $compactBlock('Equipment Evidence: '.$equipmentName, 'General equipment remarks', $generalRemarks);
                        }
                    @endphp
                    @foreach ($section['status_fields'] as $field)
                        @php
                            $statusValue = trim((string) ($check[$field['status']] ?? $check[$field['status_snake']] ?? ''));
                            $issueRemarks = trim((string) ($check[$field['remarks']] ?? $check[$field['remarks_snake']] ?? ''));
                            $issuePhotos = $filterInlinePhotos($check[$field['photos']] ?? $check[$field['photos_snake']] ?? []);
                            $issuePhotoColumns = count($issuePhotos) > 1 ? 2 : 1;
                            $issueTitle = 'Issue Evidence: '.$equipmentName.' - '.$field['label'];
                            $compactIssueOnly = strcasecmp($statusValue, 'Not Good') === 0 && count($issuePhotos) === 0 && $isCompactText($issueRemarks);
                            if ($compactIssueOnly) {
                                $compactBlocks[] = $compactBlock($issueTitle, 'Issue remarks', $issueRemarks);
                            }
                        @endphp
                        @if (strcasecmp($statusValue, 'Not Good') === 0 && ! $compactIssueOnly && ($issueRemarks !== '' || count($issuePhotos) > 0))
                            <div class="text-block-label" style="margin-top: 10px;">
                                {{ $issueTitle }}
                            </div>
                            @if ($issueRemarks !== '')
                                <div class="text-block-value">{{ $issueRemarks }}</div>
                            @endif
                            @if (count($issuePhotos) > 0)
                                <table class="photo-grid">
                                    @foreach (array_chunk($issuePhotos, $issuePhotoColumns) as $photoRow)
                                        <tr>
                                            @foreach ($photoRow as $photo)
                                                @php
                                                    $description = trim((string) ($photo['description'] ?? ''));
                                                    if ($description === '') {
                                                        $description = 'Image description not provided by user';
                                                    }
                                                    $description = preg_replace('/\s+/u', ' ', trim($description));
                                                    if ($description !== '') {
                                                        $descriptionLower = mb_strtolower($description, 'UTF-8');
                                                        $description = mb_strtoupper(mb_substr($descriptionLower, 0, 1, 'UTF-8'), 'UTF-8')
                                                            . mb_substr($descriptionLower, 1, null, 'UTF-8');
                                                    }
                                                    if (!preg_match('/[.!?]$/u', $description)) {
                                                        $description .= '.';
                                                    }
                                                @endphp
                                                <td style="width: {{ $issuePhotoColumns === 1 ? '100%' : '50%' }};">
                                                    <div class="photo-card">
                                                        <div class="photo-figure">
                                                            <div class="photo-image-wrap">
                                                                <img class="photo-image" src="{{ trim((string) ($photo['url'] ?? '')) }}" alt="SCBA issue photo">
                                                            </div>
                                                            <div class="photo-caption">
                                                                <div class="photo-description">{{ $description }}</div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                            @endforeach
                                            @if ($issuePhotoColumns === 2 && count($photoRow) === 1)
                                                <td></td>
                                            @endif
                                        </tr>
                                    @endforeach
                                </table>
                            @endif
                        @endif
                    @endforeach
                    @if (count($additionalPhotos) > 0)
                        <div class="text-block-label" style="margin-top: 10px;">
                            Equipment Evidence: {{ $equipmentName }}
                        </div>
                        <table class="photo-grid">
                            @foreach (array_chunk($additionalPhotos, $additionalPhotoColumns) as $photoRow)
                                <tr>
                                    @foreach ($photoRow as $photo)
                                        @php
                                            $description = trim((string) ($photo['description'] ?? ''));
                                            if ($description === '') {
                                                $description = 'Image description not provided by user';
                                            }
                                            $description = preg_replace('/\s+/u', ' ', trim($description));
                                            if ($description !== '') {
                                                $descriptionLower = mb_strtolower($description, 'UTF-8');
                                                $description = mb_strtoupper(mb_substr($descriptionLower, 0, 1, 'UTF-8'), 'UTF-8')
                                                    . mb_substr($descriptionLower, 1, null, 'UTF-8');
                                            }
                                            if (!preg_match('/[.!?]$/u', $description)) {
                                                $description .= '.';
                                            }
                                        @endphp
                                        <td style="width: {{ $additionalPhotoColumns === 1 ? '100%' : '50%' }};">
                                            <div class="photo-card">
                                                <div class="photo-figure">
                                                    <div class="photo-image-wrap">
                                                        <img class="photo-image" src="{{ trim((string) ($photo['url'] ?? '')) }}" alt="SCBA equipment photo">
                                                    </div>
                                                    <div class="photo-caption">
                                                        <div class="photo-description">{{ $description }}</div>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                    @endforeach
                                    @if ($additionalPhotoColumns === 2 && count($photoRow) === 1)
                                        <td></td>
                                    @endif
                                </tr>
                            @endforeach
                        </table>
                    @endif
                @endforeach
                {!! $renderCompactBlocks($compactBlocks) !!}
            @endif
        @endforeach
    </div>
</div>
@endif

@if ($isHighAngleInspection && count($highAngleChecks) > 0)
<div class="card">
    <div class="card-head">High Angle Rescue Equipment Checks</div>
    <div class="card-body">
        <div class="meta-grid" style="margin-bottom: 8px;">
            <div class="meta-cell" style="width: 50%;">
                <div class="meta-label">Inspected By</div>
                <div class="meta-value">{{ $highAngleInspectedBy !== '' ? $highAngleInspectedBy : '--' }}</div>
                @if ($inspectedByRole !== '')
                    <div class="person-meta">{{ $inspectedByRole }}</div>
                @endif
            </div>
            <div class="meta-cell" style="width: 50%;">
                <div class="meta-label">Inspection Date</div>
                <div class="meta-value">{{ $highAngleInspectionDate !== '' ? $highAngleInspectionDate : '--' }}</div>
            </div>
        </div>
        @foreach ($highAngleGroups as $group)
            <div class="text-block-label" style="margin: {{ $loop->first ? '0' : '10px' }} 0 4px; font-weight: 700; color: #374151;">
                {{ $group['title'] }}
            </div>
            <table class="hydraulic-checks">
                <thead>
                    <tr>
                        <th style="width: 8%;">Row</th>
                        <th style="width: 16%;">Storage</th>
                        <th style="width: 16%;">Compartment</th>
                        <th style="width: 28%;">Equipment</th>
                        <th style="width: 10%;">Quantity</th>
                        <th style="width: 10%;">Condition</th>
                        <th style="width: 12%;">Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($group['rows'] as $check)
                        <tr>
                            <td>{{ trim((string) ($check['rowNumber'] ?? $check['row_number'] ?? '')) ?: '--' }}</td>
                            <td>{{ trim((string) ($check['location'] ?? '')) ?: '--' }}</td>
                            <td>{{ trim((string) ($check['subLocation'] ?? $check['sub_location'] ?? '')) ?: '--' }}</td>
                            <td>{{ trim((string) ($check['equipment'] ?? '')) ?: '--' }}</td>
                            <td>{{ trim((string) ($check['quantity'] ?? '')) ?: '--' }}</td>
                            <td>{{ trim((string) ($check['condition'] ?? '')) ?: '--' }}</td>
                            <td>{{ trim((string) ($check['conditionRemarks'] ?? $check['condition_remarks'] ?? $check['remarks'] ?? '')) ?: '--' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            @php $compactBlocks = []; @endphp
            @foreach ($group['rows'] as $check)
                @php
                    $condition = trim((string) ($check['condition'] ?? ''));
                    $issueRemarks = trim((string) ($check['conditionRemarks'] ?? $check['condition_remarks'] ?? $check['remarks'] ?? ''));
                    $issuePhotos = $filterInlinePhotos($check['conditionPhotos'] ?? $check['condition_photos'] ?? []);
                    if (count($issuePhotos) === 0) {
                        $issuePhotos = $filterInlinePhotos($check['photos'] ?? []);
                    }
                    $issuePhotoColumns = count($issuePhotos) > 1 ? 2 : 1;
                    $equipmentName = trim((string) ($check['equipment'] ?? '')) ?: 'High Angle equipment';
                    $additionalNotes = trim((string) ($check['additionalNotes'] ?? $check['additional_notes'] ?? ''));
                    $additionalPhotos = $filterInlinePhotos($check['additionalPhotos'] ?? $check['additional_photos'] ?? []);
                    $additionalPhotoColumns = count($additionalPhotos) > 1 ? 2 : 1;
                    $hasIssue = strcasecmp($condition, 'Not Good') === 0;
                    $issueTitle = 'Issue Evidence: '.$equipmentName;
                    $additionalTitle = 'Additional Info: '.$equipmentName;
                    $compactIssueOnly = $hasIssue && count($issuePhotos) === 0 && $isCompactText($issueRemarks);
                    $compactAdditionalOnly = count($additionalPhotos) === 0 && $isCompactText($additionalNotes);
                    if ($compactIssueOnly) {
                        $compactBlocks[] = $compactBlock($issueTitle, 'Condition remarks', $issueRemarks);
                    }
                    if ($compactAdditionalOnly) {
                        $compactBlocks[] = $compactBlock($additionalTitle, 'General equipment remarks', $additionalNotes);
                    }
                @endphp
                @if ($hasIssue && ! $compactIssueOnly && ($issueRemarks !== '' || count($issuePhotos) > 0))
                    <div class="text-block-label" style="margin-top: 10px;">
                        {{ $issueTitle }}
                    </div>
                    @if ($issueRemarks !== '')
                        <div class="text-block-value">{{ $issueRemarks }}</div>
                    @endif
                    @if (count($issuePhotos) > 0)
                        <table class="photo-grid">
                            @foreach (array_chunk($issuePhotos, $issuePhotoColumns) as $photoRow)
                                <tr>
                                    @foreach ($photoRow as $photo)
                                        @php $description = $formatPhotoDescription($photo); @endphp
                                        <td style="width: {{ $issuePhotoColumns === 1 ? '100%' : '50%' }};">
                                            <div class="photo-card">
                                                <div class="photo-figure">
                                                    <div class="photo-image-wrap">
                                                        <img class="photo-image" src="{{ trim((string) ($photo['url'] ?? '')) }}" alt="High Angle issue photo">
                                                    </div>
                                                    <div class="photo-caption">
                                                        <div class="photo-description">{{ $description }}</div>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                    @endforeach
                                    @if ($issuePhotoColumns === 2 && count($photoRow) === 1)
                                        <td></td>
                                    @endif
                                </tr>
                            @endforeach
                        </table>
                    @endif
                @endif
                @if (! $compactAdditionalOnly && ($additionalNotes !== '' || count($additionalPhotos) > 0))
                    <div class="text-block-label" style="margin-top: 10px;">
                        {{ $additionalTitle }}
                    </div>
                    @if ($additionalNotes !== '')
                        <div class="text-block-label">General equipment remarks</div>
                        <div class="text-block-value">{{ $additionalNotes }}</div>
                    @endif
                    @if (count($additionalPhotos) > 0)
                        <table class="photo-grid">
                            @foreach (array_chunk($additionalPhotos, $additionalPhotoColumns) as $photoRow)
                                <tr>
                                    @foreach ($photoRow as $photo)
                                        @php $description = $formatPhotoDescription($photo); @endphp
                                        <td style="width: {{ $additionalPhotoColumns === 1 ? '100%' : '50%' }};">
                                            <div class="photo-card">
                                                <div class="photo-figure">
                                                    <div class="photo-image-wrap">
                                                        <img class="photo-image" src="{{ trim((string) ($photo['url'] ?? '')) }}" alt="High Angle additional photo">
                                                    </div>
                                                    <div class="photo-caption">
                                                        <div class="photo-description">{{ $description }}</div>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                    @endforeach
                                    @if ($additionalPhotoColumns === 2 && count($photoRow) === 1)
                                        <td></td>
                                    @endif
                                </tr>
                            @endforeach
                        </table>
                    @endif
                @endif
            @endforeach
            {!! $renderCompactBlocks($compactBlocks) !!}
        @endforeach
    </div>
</div>
@endif

@if ($isErAuxInspection && count($erAuxChecks) > 0)
<div class="card">
    <div class="card-head">ER Aux Equipment Checks</div>
    <div class="card-body">
        <div class="meta-grid" style="margin-bottom: 8px;">
            <div class="meta-cell" style="width: 50%;">
                <div class="meta-label">Inspected By</div>
                <div class="meta-value">{{ $erAuxInspectedBy !== '' ? $erAuxInspectedBy : '--' }}</div>
                @if ($inspectedByRole !== '')
                    <div class="person-meta">{{ $inspectedByRole }}</div>
                @endif
            </div>
            <div class="meta-cell" style="width: 50%;">
                <div class="meta-label">Inspection Date</div>
                <div class="meta-value">{{ $erAuxInspectionDate !== '' ? $erAuxInspectionDate : '--' }}</div>
            </div>
        </div>
        <table class="hydraulic-checks">
            <thead>
                <tr>
                    <th style="width: 28%;">Equipment</th>
                    <th style="width: 14%;">Location</th>
                    <th style="width: 12%;">Quantity</th>
                    <th style="width: 12%;">Condition</th>
                    <th style="width: 34%;">Remarks</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($erAuxChecks as $check)
                    @php
                        $equipmentDescription = trim((string) ($check['equipmentDescription'] ?? $check['equipment_description'] ?? ''));
                        $quantity = trim((string) ($check['quantity'] ?? $check['qty'] ?? $check['defaultQuantity'] ?? $check['default_quantity'] ?? ''));
                        $condition = trim((string) ($check['condition'] ?? ''));
                        $remarks = trim((string) ($check['remarks'] ?? $check['remark'] ?? ''));
                        $defectRemarks = trim((string) ($check['defectRemarks'] ?? $check['defect_remarks'] ?? ''));
                        $additionalNotes = trim((string) ($check['additionalNotes'] ?? $check['additional_notes'] ?? ''));
                        $defectPhotos = $filterInlinePhotos($check['defectPhotos'] ?? $check['defect_photos'] ?? []);
                        $additionalPhotos = $filterInlinePhotos($check['photos'] ?? []);
                        $erAuxEvidence = [];
                        if ($defectRemarks !== '') {
                            $erAuxEvidence[] = 'Defect: '.$defectRemarks;
                        }
                        if ($additionalNotes !== '') {
                            $erAuxEvidence[] = 'Additional: '.$additionalNotes;
                        } elseif ($remarks !== '') {
                            $erAuxEvidence[] = $remarks;
                        }
                    @endphp
                    <tr>
                        <td>
                            {{ trim((string) ($check['equipment'] ?? '')) ?: '--' }}
                            @if (($check['equipmentSource'] ?? $check['equipment_source'] ?? '') === 'custom' || ($check['isCustomEquipment'] ?? $check['is_custom_equipment'] ?? false))
                                <span class="pill">Custom</span>
                            @endif
                            @if ($equipmentDescription !== '')
                                <div style="margin-top: 3px; color: #6b7280; font-size: 10px; line-height: 1.35;">{{ $equipmentDescription }}</div>
                            @endif
                        </td>
                        <td>{{ trim((string) ($check['location'] ?? '')) ?: '--' }}</td>
                        <td>{{ $quantity !== '' ? $quantity : '--' }}</td>
                        <td>{{ $condition !== '' ? $condition : '--' }}</td>
                        <td>{{ count($erAuxEvidence) > 0 ? implode('; ', $erAuxEvidence) : '--' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @php $compactBlocks = []; @endphp
        @foreach ($erAuxChecks as $check)
            @php
                $equipmentName = trim((string) ($check['equipment'] ?? '')) ?: 'ER Aux equipment';
                $defectRemarks = trim((string) ($check['defectRemarks'] ?? $check['defect_remarks'] ?? ''));
                $defectPhotos = $filterInlinePhotos($check['defectPhotos'] ?? $check['defect_photos'] ?? []);
                $defectPhotoColumns = count($defectPhotos) > 1 ? 2 : 1;
                $additionalRemarks = trim((string) ($check['additionalNotes'] ?? $check['additional_notes'] ?? $check['remarks'] ?? $check['remark'] ?? ''));
                $additionalPhotos = $filterInlinePhotos($check['photos'] ?? []);
                $additionalPhotoColumns = count($additionalPhotos) > 1 ? 2 : 1;
                $defectTitle = 'Defect Evidence: '.$equipmentName;
                $additionalTitle = 'Additional Evidence: '.$equipmentName;
                $compactDefectOnly = count($defectPhotos) === 0 && $isCompactText($defectRemarks);
                $compactAdditionalOnly = count($additionalPhotos) === 0 && $isCompactText($additionalRemarks);
                if ($compactDefectOnly) {
                    $compactBlocks[] = $compactBlock($defectTitle, 'Defect remarks', $defectRemarks);
                }
                if ($compactAdditionalOnly) {
                    $compactBlocks[] = $compactBlock($additionalTitle, 'General equipment remarks', $additionalRemarks);
                }
            @endphp
            @if (! $compactDefectOnly && ($defectRemarks !== '' || count($defectPhotos) > 0))
                <div class="text-block-label" style="margin-top: 10px;">
                    {{ $defectTitle }}
                </div>
                @if ($defectRemarks !== '')
                    <div class="text-block-value">{{ $defectRemarks }}</div>
                @endif
                @if (count($defectPhotos) > 0)
                    <table class="photo-grid">
                        @foreach (array_chunk($defectPhotos, $defectPhotoColumns) as $photoRow)
                            <tr>
                                @foreach ($photoRow as $photo)
                                    @php $description = $formatPhotoDescription($photo); @endphp
                                    <td style="width: {{ $defectPhotoColumns === 1 ? '100%' : '50%' }};">
                                        <div class="photo-card">
                                            <div class="photo-figure">
                                                <div class="photo-image-wrap">
                                                    <img class="photo-image" src="{{ trim((string) ($photo['url'] ?? '')) }}" alt="ER Aux defect photo">
                                                </div>
                                                <div class="photo-caption">
                                                    <div class="photo-description">{{ $description }}</div>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                @endforeach
                                @if ($defectPhotoColumns === 2 && count($photoRow) === 1)
                                    <td></td>
                                @endif
                            </tr>
                        @endforeach
                    </table>
                @endif
            @endif
            @if (! $compactAdditionalOnly && ($additionalRemarks !== '' || count($additionalPhotos) > 0))
                <div class="text-block-label" style="margin-top: 10px;">
                    {{ $additionalTitle }}
                </div>
                @if ($additionalRemarks !== '')
                    <div class="text-block-value">{{ $additionalRemarks }}</div>
                @endif
                @if (count($additionalPhotos) > 0)
                    <table class="photo-grid">
                        @foreach (array_chunk($additionalPhotos, $additionalPhotoColumns) as $photoRow)
                            <tr>
                                @foreach ($photoRow as $photo)
                                    @php $description = $formatPhotoDescription($photo); @endphp
                                    <td style="width: {{ $additionalPhotoColumns === 1 ? '100%' : '50%' }};">
                                        <div class="photo-card">
                                            <div class="photo-figure">
                                                <div class="photo-image-wrap">
                                                    <img class="photo-image" src="{{ trim((string) ($photo['url'] ?? '')) }}" alt="ER Aux additional photo">
                                                </div>
                                                <div class="photo-caption">
                                                    <div class="photo-description">{{ $description }}</div>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                @endforeach
                                @if ($additionalPhotoColumns === 2 && count($photoRow) === 1)
                                    <td></td>
                                @endif
                            </tr>
                        @endforeach
                    </table>
                @endif
            @endif
        @endforeach
        {!! $renderCompactBlocks($compactBlocks) !!}
    </div>
</div>
@endif

@if (($isGeneralInspection || $isHseInspection) && count($inspectionIssues) > 0)
<div class="card">
    <div class="card-head">Findings ({{ count($inspectionIssues) }})</div>
    <div class="card-body">
        @foreach ($inspectionIssues as $issueIndex => $issue)
            @php
                $issuePhotos = $issue['photos'];
                $issuePhotoColumns = count($issuePhotos) > 1 ? 2 : 1;
                $compactFinding = count($issuePhotos) === 0
                    && $issue['description'] !== ''
                    && $issue['actionRequired'] !== ''
                    && $isCompactText($issue['description'])
                    && $isCompactText($issue['actionRequired']);
            @endphp
            <div class="issue-block">
                <div class="issue-title">Finding {{ $issueIndex + 1 }}</div>
                @if ($compactFinding)
                    {!! $renderCompactBlocks([
                        $compactBlock('Description', '', $issue['description']),
                        $compactBlock('Action Required', '', $issue['actionRequired']),
                    ]) !!}
                @else
                    @if ($issue['description'] !== '')
                        <div class="text-block-label">Description</div>
                        <div class="text-block-value">{{ $issue['description'] }}</div>
                    @endif
                    @if ($issue['actionRequired'] !== '')
                        <div class="divider"></div>
                        <div class="text-block-label">Action Required</div>
                        <div class="text-block-value">{{ $issue['actionRequired'] }}</div>
                    @endif
                @endif
                @if (count($issuePhotos) > 0)
                    <div class="divider"></div>
                    <div class="text-block-label">Finding Photos</div>
                    <table class="photo-grid">
                        @foreach (array_chunk($issuePhotos, $issuePhotoColumns) as $photoRow)
                            <tr>
                                @foreach ($photoRow as $photo)
                                    @php $description = $formatPhotoDescription($photo); @endphp
                                    <td style="width: {{ $issuePhotoColumns === 1 ? '100%' : '50%' }};">
                                        <div class="photo-card">
                                            <div class="photo-figure">
                                                <div class="photo-image-wrap">
                                                    <img class="photo-image" src="{{ trim((string) ($photo['url'] ?? '')) }}" alt="Inspection finding photo">
                                                </div>
                                                <div class="photo-caption">
                                                    <div class="photo-description">{{ $description }}</div>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                @endforeach
                                @if ($issuePhotoColumns === 2 && count($photoRow) === 1)
                                    <td></td>
                                @endif
                            </tr>
                        @endforeach
                    </table>
                @endif
            </div>
        @endforeach
    </div>
</div>
@endif

<div class="card">
    <div class="card-head">Photographs ({{ count($photos) }})</div>
    <div class="card-body">
        @if (!count($photos))
            <div class="text-block-value">No photos uploaded.</div>
        @else
            @php $figureIndex = 0; @endphp
            <table class="photo-grid">
                @foreach (array_chunk($photos, $photoColumns) as $photoRow)
                    <tr>
                        @foreach ($photoRow as $photo)
                            @php
                                $figureIndex++;
                                $description = trim((string) ($photo['description'] ?? ''));
                                if ($description === '') {
                                    $description = 'Image description not provided by user';
                                }
                                $description = preg_replace('/\s+/u', ' ', trim($description));
                                if ($description !== '') {
                                    $descriptionLower = mb_strtolower($description, 'UTF-8');
                                    $description = mb_strtoupper(mb_substr($descriptionLower, 0, 1, 'UTF-8'), 'UTF-8')
                                        . mb_substr($descriptionLower, 1, null, 'UTF-8');
                                }
                                if (!preg_match('/[.!?]$/u', $description)) {
                                    $description .= '.';
                                }
                            @endphp
                            <td style="width: {{ $photoColumns === 1 ? '100%' : '50%' }};">
                                <div class="photo-card">
                                    <div class="photo-figure">
                                        <div class="photo-image-wrap">
                                            <img class="photo-image" src="{{ trim((string) ($photo['url'] ?? '')) }}" alt="Inspection photo">
                                        </div>
                                        <div class="photo-caption">
                                            <div class="photo-description">Figure {{ $figureIndex }}. {{ $description }}</div>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        @endforeach
                        @if ($photoColumns === 2 && count($photoRow) === 1)
                            <td></td>
                        @endif
                    </tr>
                @endforeach
            </table>
        @endif
    </div>
</div>

<div class="card">
    <div class="card-head">Workflow Sign-offs</div>
    <div class="card-body" style="padding:0;">
        <table class="workflow">
            <thead>
                <tr>
                    <th>Prepared By</th>
                    <th>Reviewed By</th>
                    <th>Approved By</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        @if (is_array($submittedEntry))
                            @php
                                $preparedBy = trim((string) ($submittedEntry['by'] ?? $submittedBy));
                                $preparedAt = $fmtDateTime($submittedEntry['at'] ?? $submittedAtRaw);
                                $preparedRemarks = trim((string) ($submittedEntry['remarks'] ?? ''));
                                $preparedRole = $entryActorRole($submittedEntry) ?: $submittedByRole;
                            @endphp
                            @if ($preparedBy !== '')
                                <div class="person-name">{{ $preparedBy }}</div>
                            @endif
                            @if ($preparedRole !== '')
                                <div class="person-meta">{{ $preparedRole }}</div>
                            @endif
                            @if ($preparedAt !== '')
                                <div class="person-meta">{{ $preparedAt }}</div>
                            @endif
                            @if ($preparedRemarks !== '')
                                <div class="person-remarks">Remarks: {{ $preparedRemarks }}</div>
                            @endif
                        @elseif ($submittedBy !== '' || $submittedAt !== '')
                            @if ($submittedBy !== '')
                                <div class="person-name">{{ $submittedBy }}</div>
                            @endif
                            @if ($submittedByRole !== '')
                                <div class="person-meta">{{ $submittedByRole }}</div>
                            @endif
                            @if ($submittedAt !== '')
                                <div class="person-meta">{{ $submittedAt }}</div>
                            @endif
                        @else
                            <span class="pending">Pending</span>
                        @endif
                    </td>
                    <td>
                        @if (is_array($reviewedEntry))
                            @php
                                $reviewedBy = trim((string) ($reviewedEntry['by'] ?? ''));
                                $reviewedAt = $fmtDateTime($reviewedEntry['at'] ?? '');
                                $reviewedRemarks = trim((string) ($reviewedEntry['remarks'] ?? ''));
                                $reviewedRole = $entryActorRole($reviewedEntry);
                            @endphp
                            @if ($reviewedBy !== '')
                                <div class="person-name">{{ $reviewedBy }}</div>
                            @endif
                            @if ($reviewedRole !== '')
                                <div class="person-meta">{{ $reviewedRole }}</div>
                            @endif
                            @if ($reviewedAt !== '')
                                <div class="person-meta">{{ $reviewedAt }}</div>
                            @endif
                            @if ($reviewedRemarks !== '')
                                <div class="person-remarks">Remarks: {{ $reviewedRemarks }}</div>
                            @endif
                        @else
                            <span class="pending">Pending</span>
                        @endif
                    </td>
                    <td>
                        @if (is_array($approvedEntry))
                            @php
                                $approvedBy = trim((string) ($approvedEntry['by'] ?? ''));
                                $approvedAt = $fmtDateTime($approvedEntry['at'] ?? '');
                                $approvedRemarks = trim((string) ($approvedEntry['remarks'] ?? ''));
                                $approvedRole = $entryActorRole($approvedEntry);
                            @endphp
                            @if ($approvedBy !== '')
                                <div class="person-name">{{ $approvedBy }}</div>
                            @endif
                            @if ($approvedRole !== '')
                                <div class="person-meta">{{ $approvedRole }}</div>
                            @endif
                            @if ($approvedAt !== '')
                                <div class="person-meta">{{ $approvedAt }}</div>
                            @endif
                            @if ($approvedRemarks !== '')
                                <div class="person-remarks">Remarks: {{ $approvedRemarks }}</div>
                            @endif
                        @else
                            <span class="pending">Pending</span>
                        @endif
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>
