<?php

namespace App\Services\InspectionFireExtinguishers;

use App\Services\InspectionReports\PdfFooterTextFitter;
use Barryvdh\DomPDF\Facade\Pdf;
use Dompdf\Canvas;
use Dompdf\FontMetrics;
use Illuminate\Support\Facades\Log;

class FireExtinguisherExceptionPdfRenderer
{
    public function __construct(private readonly PdfFooterTextFitter $footerTextFitter) {}

    /** @param array<string, mixed> $data */
    public function render(array $data): string
    {
        $startedAt = microtime(true);
        $title = trim((string) ($data['title'] ?? 'Fire Extinguisher Exception Report'));
        $generatedAt = trim((string) ($data['generatedAtDisplay'] ?? now()->format('d M Y, H:i')));
        $document = Pdf::loadView('pdf.fire_extinguisher_exception_export', ['data' => $data])
            ->setPaper('a4')
            ->setOption([
                'defaultFont' => 'DejaVu Sans',
                'isFontSubsettingEnabled' => true,
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled' => false,
                'isPhpEnabled' => false,
                'isJavascriptEnabled' => false,
            ]);

        $document->render();
        $document->getDomPDF()->getCanvas()->page_script(
            function (int $pageNumber, int $pageCount, Canvas $canvas, FontMetrics $fontMetrics) use ($title, $generatedAt): void {
                $font = $fontMetrics->getFont('DejaVu Sans');
                $fontSize = 7.2;
                $suffix = sprintf(' | Page %d of %d | Generated %s', $pageNumber, $pageCount, $generatedAt);
                $pageWidth = $canvas->get_width();
                $pageHeight = $canvas->get_height();
                $footer = $this->footerTextFitter->fit(
                    $title,
                    $suffix,
                    fn (string $text): float => $fontMetrics->getTextWidth($text, $font, $fontSize),
                    $pageWidth - 80,
                );
                $textWidth = $fontMetrics->getTextWidth($footer, $font, $fontSize);
                $canvas->line(40, $pageHeight - 38, $pageWidth - 40, $pageHeight - 38, [0.9, 0.91, 0.93], 0.6);
                $canvas->text(max(40, ($pageWidth - $textWidth) / 2), $pageHeight - 30, $footer, $font, $fontSize, [0.61, 0.64, 0.69]);
            },
        );

        $output = $document->output(['compress' => 1]);
        Log::info('fire_extinguisher_exception_pdf_rendered', [
            'record_count' => (int) data_get($data, 'summary.total', 0),
            'page_count' => $document->getDomPDF()->getCanvas()->get_page_count(),
            'output_bytes' => strlen($output),
            'image_count' => (int) data_get($data, 'renderMeta.imageCount', 0),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return $output;
    }
}
