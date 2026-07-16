<?php

namespace App\Http\Controllers;

use App\Http\Requests\DownloadFireExtinguisherExceptionExportRequest;
use App\Http\Requests\FireExtinguisherExceptionExportRequest;
use App\Services\AssignmentAuthorizationService;
use App\Services\AuditLogger;
use App\Services\InspectionFireExtinguishers\FireExtinguisherExceptionDocxRenderer;
use App\Services\InspectionFireExtinguishers\FireExtinguisherExceptionExportBuilder;
use App\Services\InspectionFireExtinguishers\FireExtinguisherExceptionPdfRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class FireExtinguisherExceptionExportController extends Controller
{
    public function __construct(
        private readonly AssignmentAuthorizationService $authorizationService,
        private readonly FireExtinguisherExceptionExportBuilder $builder,
        private readonly FireExtinguisherExceptionPdfRenderer $pdfRenderer,
        private readonly FireExtinguisherExceptionDocxRenderer $docxRenderer,
    ) {}

    public function preview(FireExtinguisherExceptionExportRequest $request): JsonResponse
    {
        $this->ensureInspectionPermission($request);

        return response()->json(['data' => $this->builder->preview($request->validated())]);
    }

    public function download(DownloadFireExtinguisherExceptionExportRequest $request): Response
    {
        $this->ensureInspectionPermission($request);
        $validated = $request->validated();
        $format = (string) $validated['format'];
        $data = $this->builder->build($validated, $request->user());
        $output = $format === 'docx'
            ? $this->docxRenderer->render($data)
            : $this->pdfRenderer->render($data);
        $filename = $this->filename((array) ($validated['categories'] ?? []), $format);
        $contentType = $format === 'docx'
            ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            : 'application/pdf';

        AuditLogger::log($request, 'fire_extinguisher_exception_export_downloaded', null, [
            'format' => $format,
            'categories' => array_values((array) ($validated['categories'] ?? [])),
            'scope' => (string) ($validated['scope'] ?? 'current_filters'),
            'filters' => (array) ($validated['filters'] ?? []),
            'record_count' => (int) data_get($data, 'summary.total', 0),
            'issue_count' => (int) data_get($data, 'summary.issues', 0),
            'expired_count' => (int) data_get($data, 'summary.expired', 0),
            'overlap_count' => (int) data_get($data, 'summary.overlap', 0),
        ]);

        return response($output, 200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'attachment; filename="'.$filename.'"; filename*=UTF-8\'\''.rawurlencode($filename),
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Length' => strlen($output),
            'X-Export-Record-Count' => (string) data_get($data, 'summary.total', 0),
        ]);
    }

    /** @param array<int, string> $categories */
    private function filename(array $categories, string $format): string
    {
        $categories = array_map(fn ($category): string => strtolower(trim((string) $category)), $categories);
        $slug = in_array('issues', $categories, true) && in_array('expired', $categories, true)
            ? 'issues-and-expired'
            : (in_array('expired', $categories, true) ? 'expired' : 'issues');

        return 'fire-extinguisher-'.$slug.'-'.now()->format('Y-m-d').'.'.$format;
    }

    private function ensureInspectionPermission(FireExtinguisherExceptionExportRequest $request): void
    {
        $user = $request->user();
        if (! $user || ! $this->authorizationService->hasPermission($user, 'reports.manage|reports.inspection.view')) {
            abort(403, 'Missing inspection report permission.');
        }
    }
}
