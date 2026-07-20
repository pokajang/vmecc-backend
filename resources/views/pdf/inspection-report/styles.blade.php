<style>
        @page { size: A4; margin: 14mm 14mm 24mm 14mm; }
        * { box-sizing: border-box; }
        body {
            font-family: "DejaVu Sans", sans-serif;
            color: #111827;
            font-size: 10px;
            line-height: 1.35;
            margin: 0;
        }
        table th, table td { word-break: break-word; }
        .header {
            width: 100%;
            table-layout: fixed;
            border-collapse: collapse;
            margin-bottom: 10px;
            border-bottom: 2px solid #0b948f;
            padding-bottom: 8px;
        }
        .header-left { width: 40%; padding: 0; vertical-align: bottom; }
        .header-right { width: 60%; padding: 0; vertical-align: bottom; text-align: right; }
        .report-title {
            font-size: 15px;
            font-weight: 700;
            color: #0b948f;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }
        .report-subtitle { font-size: 9px; color: #6b7280; margin-top: 1px; }
        .report-id {
            font-size: 11.5px;
            font-weight: 700;
            color: #111827;
            line-height: 1.2;
            overflow-wrap: break-word;
            word-break: break-word;
        }
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
        }
        .card--compact {
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
            page-break-after: avoid;
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
        .text-block-label {
            font-size: 8.5px;
            color: #6b7280;
            margin-bottom: 2px;
            page-break-after: avoid;
        }
        .report-evidence-photos { margin-top: 0; }
        .report-evidence-photos--spaced { margin-top: 10px; }
        .text-block-value {
            font-size: 10px;
            line-height: 1.5;
            word-break: break-word;
            white-space: pre-wrap;
        }
        .inspected-locations-card { page-break-inside: auto; }
        .inspection-location-grid {
            display: table;
            width: 100%;
            table-layout: fixed;
        }
        .inspection-location-item {
            display: inline-block;
            width: 48%;
            margin: 0 1% 2px 0;
            color: #374151;
            font-size: 9px;
            line-height: 1.35;
            vertical-align: top;
            word-break: break-word;
            page-break-inside: avoid;
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
        .workflow td { min-height: 36px; font-size: 9px; word-break: break-word; }
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
        .hydraulic-checks tr.inspection-check-evidence-row {
            page-break-inside: auto;
        }
        .hydraulic-checks tr.inspection-check-evidence-row > td {
            padding: 0;
            border-top: 0;
            background: #f8fafc;
        }
        .inspection-check-evidence {
            padding: 6px 7px 7px 10px;
            border-left: 3px solid #0b948f;
        }
        .inspection-check-evidence__spaced { margin-top: 7px; }
        .inspection-check-evidence > .compact-info-grid:first-child,
        .inspection-check-evidence > .evidence-grid:first-child {
            margin-top: 0;
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
        .person-name { font-weight: 600; font-size: 9.5px; color: #111827; word-break: break-word; }
        .workflow-card-body { padding: 0; }
        .person-meta { font-size: 8.5px; color: #6b7280; margin-top: 2px; }
        .person-remarks {
            font-size: 8.5px;
            color: #4b5563;
            margin-top: 3px;
            line-height: 1.35;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .evidence-grid {
            width: 100%;
            border-collapse: separate;
            border-spacing: 4px;
            table-layout: fixed;
            margin: 4px -4px 0;
        }
        .evidence-grid > tbody > tr {
            page-break-inside: avoid;
        }
        .evidence-grid > tbody > tr > td {
            width: 50%;
            padding: 0;
            vertical-align: top;
        }
        .evidence-grid > tbody > tr > td.evidence-grid-empty {
            border: none;
        }
        .evidence-card {
            border: 1px solid #e5e7eb;
            padding: 6px;
            page-break-inside: avoid;
        }
        .evidence-kind {
            color: #0b948f;
            font-size: 7.5px;
            font-weight: 700;
            letter-spacing: 0.05em;
            line-height: 1.2;
            margin-bottom: 2px;
            text-transform: uppercase;
        }
        .evidence-title {
            color: #111827;
            font-size: 9px;
            font-weight: 700;
            line-height: 1.3;
            margin-bottom: 3px;
            word-break: break-word;
        }
        .evidence-remarks {
            color: #374151;
            font-size: 8.5px;
            line-height: 1.35;
            margin-bottom: 4px;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .evidence-image-wrap {
            height: 165px;
            padding: 3px 0;
            text-align: center;
            vertical-align: middle;
        }
        .evidence-image-unavailable {
            display: table;
            width: 100%;
            height: 100%;
            min-height: 88px;
            background: #f3f4f6;
            border: 1px dashed #cbd5e1;
            color: #64748b;
            text-align: center;
        }
        .evidence-image-unavailable span {
            display: table-cell;
            vertical-align: middle;
            font-size: 8.5px;
            font-weight: 600;
        }
        .evidence-image {
            display: inline-block;
            height: auto;
            max-height: 159px;
            max-width: 100%;
            width: auto;
        }
        .evidence-description {
            color: #4b5563;
            font-size: 8px;
            line-height: 1.35;
            margin-top: 4px;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .evidence-single-layout {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        .evidence-single-media {
            width: 42%;
            padding: 0 8px 0 0;
            vertical-align: middle;
        }
        .evidence-single-copy {
            width: 58%;
            padding: 0;
            vertical-align: top;
        }
        .issue-block {
            border: 1px solid #e5e7eb;
            padding: 6px 7px;
            margin-bottom: 7px;
            page-break-inside: auto;
        }
        .issue-title {
            font-weight: 700;
            font-size: 9.5px;
            color: #111827;
            margin-bottom: 4px;
            page-break-after: avoid;
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
        table thead { display: table-header-group; }
        table tfoot { display: table-row-group; }
        table.hydraulic-checks > tbody > tr,
        table.workflow > tbody > tr {
            page-break-inside: avoid;
        }
    </style>
