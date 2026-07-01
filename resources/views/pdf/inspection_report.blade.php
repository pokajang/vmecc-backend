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
    $inspectionType = trim((string) ($record['incidentType'] ?? ''));
    $location = trim((string) ($record['location'] ?? $record['selectedLocation'] ?? ''));
    $description = (string) ($record['description'] ?? '');
    $checklist = array_values(array_filter(is_array($record['checklist'] ?? null) ? $record['checklist'] : [], function ($item) {
        return is_array($item)
            && ($item['selected'] ?? true) !== false
            && trim((string) ($item['label'] ?? '')) !== '';
    }));
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
    $hasHseObservation = count($hseSelections) > 0 || $hseInspectedBy !== '' || $hseInspectionDate !== '';
    $submittedBy = trim((string) ($record['submittedBy'] ?? ''));
    $submittedAtRaw = trim((string) ($record['submittedAt'] ?? ''));
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
        ],
    ];
    $hasScbaChecks = count($scbaBackPlateChecks) > 0 || count($scbaCylinderChecks) > 0 || count($scbaFaceMaskChecks) > 0;
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

    $submittedAt = $submittedAtRaw !== '' ? $fmtDateTime($submittedAtRaw) : '';
    if ($submittedAt === '' && is_array($submittedEntry)) {
        $submittedAt = $fmtDateTime($submittedEntry['at'] ?? '');
    }
    if ($submittedBy === '' && is_array($submittedEntry)) {
        $submittedBy = trim((string) ($submittedEntry['by'] ?? ''));
    }

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
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-head">Inspection Description</div>
    <div class="card-body">
        <div class="text-block-label">Summary</div>
        <div class="text-block-value">{{ trim($description) !== '' ? $description : 'No description provided.' }}</div>
    </div>
</div>

@if (count($checklist) > 0)
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

@if (count($fireExtinguisherChecks) > 0)
<div class="card">
    <div class="card-head">Fire Extinguisher Checks</div>
    <div class="card-body">
        <div class="meta-grid meta-grid-4" style="margin-bottom: 8px;">
            <div class="meta-cell">
                <div class="meta-label">Inspected By</div>
                <div class="meta-value">{{ $fireExtinguisherInspectedBy !== '' ? $fireExtinguisherInspectedBy : '--' }}</div>
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
        @foreach ($fireExtinguisherChecks as $check)
            @php
                $feName = trim((string) ($check['idLocNo'] ?? $check['id_loc_no'] ?? $check['barcodeNo'] ?? $check['barcode_no'] ?? '')) ?: 'Fire extinguisher';
                $fireFields = [
                    ['status' => 'physicalCondition', 'status_snake' => 'physical_condition', 'label' => 'FE Physical Condition', 'remarks' => 'physicalConditionRemarks', 'remarks_snake' => 'physical_condition_remarks'],
                    ['status' => 'signageCondition', 'status_snake' => 'signage_condition', 'label' => 'FE Signage Condition', 'remarks' => 'signageConditionRemarks', 'remarks_snake' => 'signage_condition_remarks'],
                    ['status' => 'boxKeyAvailability', 'status_snake' => 'box_key_availability', 'label' => 'FE Box Key Availability', 'remarks' => 'boxKeyAvailabilityRemarks', 'remarks_snake' => 'box_key_availability_remarks'],
                    ['status' => 'boxGlassAvailability', 'status_snake' => 'box_glass_availability', 'label' => 'FE Box Glass Availability', 'remarks' => 'boxGlassAvailabilityRemarks', 'remarks_snake' => 'box_glass_availability_remarks'],
                    ['status' => 'operationalCondition', 'status_snake' => 'operational_condition', 'label' => 'Operational Condition', 'remarks' => 'operationalConditionRemarks', 'remarks_snake' => 'operational_condition_remarks'],
                ];
            @endphp
            @foreach ($fireFields as $field)
                @php
                    $statusValue = strtolower(trim((string) ($check[$field['status']] ?? $check[$field['status_snake']] ?? '')));
                    $remarksValue = trim((string) ($check[$field['remarks']] ?? $check[$field['remarks_snake']] ?? ''));
                @endphp
                @if (in_array($statusValue, ['not good', 'no', 'not operational'], true) && $remarksValue !== '')
                    <div class="text-block-label" style="margin-top: 10px;">
                        Defect Remarks: {{ $feName }} - {{ $field['label'] }}
                    </div>
                    <div class="text-block-value">{{ $remarksValue }}</div>
                @endif
            @endforeach
        @endforeach
    </div>
</div>
@endif

@if (count($hydraulicChecks) > 0)
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
                @endphp
                @if (strcasecmp($statusValue, 'Defect') === 0 && ($defectRemarks !== '' || count($defectPhotos) > 0))
                    <div class="text-block-label" style="margin-top: 10px;">
                        Defect Evidence: {{ $equipmentName }} - {{ $field['label'] }}
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
                @if (strcasecmp($statusValue, 'N/A') === 0 && $defectRemarks !== '')
                    <div class="text-block-label" style="margin-top: 10px;">
                        N/A Reason: {{ $equipmentName }} - {{ $field['label'] }}
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
    </div>
</div>
@endif

@if ($hasFrtChecks)
<div class="card">
    <div class="card-head">FRT Daily Inspection</div>
    <div class="card-body">
        <div class="meta-grid meta-grid-4" style="margin-bottom: 8px;">
            <div class="meta-cell">
                <div class="meta-label">Inspected By</div>
                <div class="meta-value">{{ $frtInspectedBy !== '' ? $frtInspectedBy : '--' }}</div>
            </div>
            <div class="meta-cell">
                <div class="meta-label">Inspection Date</div>
                <div class="meta-value">{{ $frtInspectionDate !== '' ? $frtInspectionDate : '--' }}</div>
            </div>
            <div class="meta-cell">
                <div class="meta-label">Shift</div>
                <div class="meta-value">{{ $frtShift !== '' ? $frtShift : '--' }}</div>
            </div>
            <div class="meta-cell">
                <div class="meta-label">Main Location</div>
                <div class="meta-value">FIRE TRUCK</div>
            </div>
        </div>
        <div class="meta-grid meta-grid-4" style="margin-bottom: 8px;">
            <div class="meta-cell">
                <div class="meta-label">Plate No</div>
                <div class="meta-value">{{ trim((string) ($frtTruckReference['plateNo'] ?? $frtTruckReference['plate_no'] ?? '')) ?: '--' }}</div>
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
                FRT Daily Roster
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
                                $status = trim((string) ($check['status'] ?? ''));
                                $readingValue = trim((string) ($check['readingValue'] ?? $check['reading_value'] ?? ''));
                            @endphp
                            <tr>
                                <td>{{ trim((string) ($check['rowNumber'] ?? $check['row_number'] ?? '')) ?: '--' }}</td>
                                <td>{{ trim((string) ($check['equipment'] ?? '')) ?: '--' }}</td>
                                <td>{{ trim((string) ($check['quantity'] ?? '')) ?: '--' }}</td>
                                <td>{{ ucfirst($rowKind) }}</td>
                                <td>{{ $rowKind === 'reading' ? '--' : ($status !== '' ? $status : '--') }}</td>
                                <td>{{ $rowKind === 'reading' ? ($readingValue !== '' ? $readingValue : '--') : '--' }}</td>
                                <td>{{ trim((string) ($check['remarks'] ?? '')) ?: '--' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endforeach
            @if ($frtDailyRemarks !== '')
                <div class="text-block-label" style="margin-top: 10px;">Daily Remarks</div>
                <div class="text-block-value">{{ $frtDailyRemarks }}</div>
            @endif
        @endif

        @if (count($frtOneOffChecks) > 0)
            <div class="text-block-label" style="margin: {{ count($frtDailyChecks) > 0 ? '10px' : '0' }} 0 4px; font-weight: 700; color: #374151;">
                FRT One Off Checklist
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
            @endforeach
            @if ($frtOneOffRemarks !== '')
                <div class="text-block-label" style="margin-top: 10px;">One-off Remarks</div>
                <div class="text-block-value">{{ $frtOneOffRemarks }}</div>
            @endif
        @endif
    </div>
</div>
@endif

@if ($hasScbaChecks)
<div class="card">
    <div class="card-head">SCBA Checks</div>
    <div class="card-body">
        <div class="meta-grid" style="margin-bottom: 8px;">
            <div class="meta-cell" style="width: 50%;">
                <div class="meta-label">Inspected By</div>
                <div class="meta-value">{{ $scbaInspectedBy !== '' ? $scbaInspectedBy : '--' }}</div>
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
            @endif
        @endforeach
    </div>
</div>
@endif

@if (count($highAngleChecks) > 0)
<div class="card">
    <div class="card-head">High Angle Rescue Equipment Checks</div>
    <div class="card-body">
        <div class="meta-grid" style="margin-bottom: 8px;">
            <div class="meta-cell" style="width: 50%;">
                <div class="meta-label">Inspected By</div>
                <div class="meta-value">{{ $highAngleInspectedBy !== '' ? $highAngleInspectedBy : '--' }}</div>
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
                            <td>{{ trim((string) ($check['remarks'] ?? '')) ?: '--' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endforeach
    </div>
</div>
@endif

@if (count($erAuxChecks) > 0)
<div class="card">
    <div class="card-head">ER Aux Equipment Checks</div>
    <div class="card-body">
        <div class="meta-grid" style="margin-bottom: 8px;">
            <div class="meta-cell" style="width: 50%;">
                <div class="meta-label">Inspected By</div>
                <div class="meta-value">{{ $erAuxInspectedBy !== '' ? $erAuxInspectedBy : '--' }}</div>
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
                        <td>{{ $remarks !== '' ? $remarks : '--' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
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
                            @endphp
                            @if ($preparedBy !== '')
                                <div class="person-name">{{ $preparedBy }}</div>
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
                            @endphp
                            @if ($reviewedBy !== '')
                                <div class="person-name">{{ $reviewedBy }}</div>
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
                            @endphp
                            @if ($approvedBy !== '')
                                <div class="person-name">{{ $approvedBy }}</div>
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
