<?php

namespace App\Http\Controllers;

use App\Models\Report;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class ErcoReportPdfController extends Controller
{
    public function download(Request $request)
    {
        $record = $request->input('record');
        $reportUid = trim((string) $request->input('report_uid', ''));
        $version = $request->input('version');

        if ($reportUid !== '') {
            $report = Report::query()
                ->with('timelineEntries')
                ->where('report_uid', $reportUid)
                ->first();
            if (! $report) {
                return response()->json(['message' => 'Report not found.'], 404);
            }
            if ($version !== null && (int) $version !== (int) $report->version) {
                return response()->json([
                    'message' => 'Version conflict. Reload latest report before downloading.',
                    'code' => 'REPORT_VERSION_CONFLICT',
                    'currentVersion' => (int) $report->version,
                ], 409);
            }
            $payload = is_array($report->payload) ? $report->payload : [];
            $payload['id'] = $report->report_uid;
            $payload['displayId'] = $report->display_id;
            $payload['reportType'] = $report->report_type;
            $payload['status'] = $report->status;
            $payload['timeline'] = $report->timelineEntries->map(function ($entry) {
                return [
                    'id' => $entry->id,
                    'revision' => $entry->revision,
                    'action' => $entry->action,
                    'fromStatus' => $entry->from_status,
                    'toStatus' => $entry->to_status,
                    'by' => $entry->by_name_snapshot,
                    'byUserId' => $entry->by_user_id,
                    'at' => optional($entry->created_at)->toIso8601String(),
                    'remarks' => $entry->remarks,
                    'meta' => $entry->meta ?? [],
                ];
            })->values()->all();
            $record = $payload;
        }

        if (! is_array($record) || empty($record)) {
            return response()->json(['message' => 'Invalid record data.'], 422);
        }

        $displayId = trim((string) ($record['displayId'] ?? 'ERCO'));
        $safeId = preg_replace('/[^A-Za-z0-9\-_]/', '-', $displayId);
        $filename = strtolower("vmecc-erco-{$safeId}.pdf");

        $document = Pdf::loadView('pdf.erco_report', [
            'record' => $record,
        ])->setPaper('a4')->setOption([
            'defaultFont' => 'Helvetica',
            'isFontSubsettingEnabled' => true,
            'isHtml5ParserEnabled' => true,
            'isRemoteEnabled' => true,
        ]);

        $output = $document->output(['compress' => 1]);

        return response($output, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"; filename*=UTF-8\'\''.rawurlencode($filename),
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Length' => strlen($output),
        ]);
    }
}
