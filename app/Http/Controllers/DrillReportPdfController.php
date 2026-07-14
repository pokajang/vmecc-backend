<?php

namespace App\Http\Controllers;

use App\Models\Report;
use App\Services\AuditLogger;
use App\Services\ReportMediaService;
use App\Services\ReportReadAuthorizationService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class DrillReportPdfController extends Controller
{
    public function __construct(
        private readonly ReportReadAuthorizationService $readAuthorizationService,
        private readonly ReportMediaService $reportMediaService,
    ) {}

    public function download(Request $request)
    {
        $validated = $request->validate([
            'report_uid' => ['required', 'string', 'max:190'],
        ]);

        $reportUid = trim((string) ($validated['report_uid'] ?? ''));
        $user = $request->user();
        if (! $user || ! $this->readAuthorizationService->canViewModule($user, 'drill')) {
            abort(403, 'Forbidden');
        }

        $report = Report::query()
            ->with('timelineEntries')
            ->where('report_uid', $reportUid)
            ->where('report_type', 'drill')
            ->first();
        if (! $report) {
            return response()->json(['message' => 'Report not found.'], 404);
        }
        if (! $this->readAuthorizationService->canDownloadPdf($user, $report)) {
            return response()->json([
                'message' => 'PDF download is unavailable until the report is submitted.',
                'code' => 'REPORT_PDF_UNAVAILABLE',
            ], 422);
        }

        $record = $this->reportMediaService->hydrateLinkedPayloadForPdf(
            is_array($report->payload) ? $report->payload : [],
            'report',
            (string) $report->report_uid,
            'drill',
        );
        $record['id'] = $report->report_uid;
        $record['displayId'] = $report->display_id;
        $record['reportType'] = $report->report_type;
        $record['status'] = $report->status;
        $record['version'] = (int) $report->version;
        $record['revision'] = (int) $report->revision;
        $record['timeline'] = $report->timelineEntries->map(function ($entry) {
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

        $displayId = trim((string) ($record['displayId'] ?? 'drill-report'));
        $safeId = preg_replace('/[^A-Za-z0-9\-_]/', '-', $displayId);
        $safeId = trim((string) $safeId, '-');
        $filename = ($safeId !== '' ? $safeId : 'drill-report').'.pdf';

        $document = Pdf::loadView('pdf.drill_report', [
            'record' => $record,
        ])->setPaper('a4')->setOption([
            'defaultFont' => 'Helvetica',
            'isFontSubsettingEnabled' => true,
            'isHtml5ParserEnabled' => true,
            'isRemoteEnabled' => false,
        ]);

        $output = $document->output(['compress' => 1]);

        AuditLogger::log($request, 'report_pdf_downloaded', null, [
            'report_uid' => $report->report_uid,
            'report_type' => $report->report_type,
            'report_version' => (int) $report->version,
            'report_status' => $report->status,
            'owner_user_id' => (int) $report->owner_user_id,
        ]);

        return response($output, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"; filename*=UTF-8\'\''.rawurlencode($filename),
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'X-Report-Version' => (string) $report->version,
            'Content-Length' => strlen($output),
        ]);
    }
}
