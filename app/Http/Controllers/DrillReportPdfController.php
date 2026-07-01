<?php

namespace App\Http\Controllers;

use App\Models\Report;
use App\Services\AssignmentAuthorizationService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class DrillReportPdfController extends Controller
{
    public function __construct(
        private readonly AssignmentAuthorizationService $authorizationService,
    ) {
    }

    public function download(Request $request)
    {
        $validated = $request->validate([
            'report_uid' => ['required', 'string', 'max:190'],
            'version' => ['nullable', 'integer', 'min:1'],
        ]);

        $reportUid = trim((string) ($validated['report_uid'] ?? ''));
        $version = $request->input('version');
        $user = $request->user();
        if (! $user || ! $this->authorizationService->hasPermission($user, 'reports.manage|reports.drill.view')) {
            abort(403, 'Forbidden');
        }

        $report = Report::query()
            ->with('timelineEntries')
            ->where('owner_user_id', $user->id)
            ->where('report_uid', $reportUid)
            ->where('report_type', 'drill')
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

        $record = is_array($report->payload) ? $report->payload : [];
        $record['id'] = $report->report_uid;
        $record['displayId'] = $report->display_id;
        $record['reportType'] = $report->report_type;
        $record['status'] = $report->status;
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

        return response($output, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"; filename*=UTF-8\'\''.rawurlencode($filename),
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Length' => strlen($output),
        ]);
    }
}
