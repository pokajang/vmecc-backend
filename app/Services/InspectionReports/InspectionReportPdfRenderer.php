<?php

namespace App\Services\InspectionReports;

use Barryvdh\DomPDF\Facade\Pdf;
use Dompdf\Canvas;
use Dompdf\FontMetrics;
use Illuminate\Support\Facades\Log;

class InspectionReportPdfRenderer
{
    public function __construct(
        private readonly InspectionReportViewDataBuilder $viewDataBuilder,
        private readonly InspectionReportPhotoSanitizer $photoSanitizer,
        private readonly PdfFooterTextFitter $footerTextFitter,
    ) {}

    public function render(array $record): string
    {
        $startedAt = microtime(true);
        $sanitization = $this->photoSanitizer->sanitize($record);
        $record = $sanitization->record;
        $viewData = $this->viewDataBuilder->build($record);
        $displayId = trim((string) $viewData['displayId']) ?: 'Inspection Report';
        $generatedAt = now()->format('d M Y, H:i');

        $document = Pdf::loadView('pdf.inspection_report', [
            'record' => $record,
            'viewData' => $viewData,
        ])->setPaper('a4')->setOption([
            'defaultFont' => 'DejaVu Sans',
            'isFontSubsettingEnabled' => true,
            'isHtml5ParserEnabled' => true,
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'isJavascriptEnabled' => false,
        ]);

        $document->render();
        $document->getDomPDF()->getCanvas()->page_script(
            function (
                int $pageNumber,
                int $pageCount,
                Canvas $canvas,
                FontMetrics $fontMetrics,
            ) use ($displayId, $generatedAt): void {
                $font = $fontMetrics->getFont('DejaVu Sans');
                $fontSize = 7.2;
                $suffix = sprintf(
                    ' | Page %d of %d | Generated %s',
                    $pageNumber,
                    $pageCount,
                    $generatedAt,
                );
                $pageWidth = $canvas->get_width();
                $pageHeight = $canvas->get_height();
                $maxTextWidth = $pageWidth - 80;
                $footer = $this->footerTextFitter->fit(
                    $displayId,
                    $suffix,
                    fn (string $text): float => $fontMetrics->getTextWidth($text, $font, $fontSize),
                    $maxTextWidth,
                );
                $textWidth = $fontMetrics->getTextWidth($footer, $font, $fontSize);

                $canvas->line(40, $pageHeight - 38, $pageWidth - 40, $pageHeight - 38, [0.9, 0.91, 0.93], 0.6);
                $canvas->text(
                    max(40, ($pageWidth - $textWidth) / 2),
                    $pageHeight - 30,
                    $footer,
                    $font,
                    $fontSize,
                    [0.61, 0.64, 0.69],
                );
            },
        );

        $output = $document->output(['compress' => 1]);
        $canvas = $document->getDomPDF()->getCanvas();

        Log::info('inspection_report_pdf_rendered', [
            'inspection_type' => $viewData['type']->value,
            'page_count' => $canvas->get_page_count(),
            'output_bytes' => strlen($output),
            'image_count' => $sanitization->imageCount,
            'unavailable_image_count' => $sanitization->unavailableImageCount,
            'omitted_image_count' => $sanitization->omittedImageCount,
            'image_bytes' => $sanitization->totalImageBytes,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return $output;
    }
}
