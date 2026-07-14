<?php

namespace App\Services\AiHelper;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

final class PdfOcrService
{
    /**
     * @return array{
     *     attempted: bool,
     *     text: string,
     *     error: ?string,
     *     has_visual_content: ?bool,
     *     visual_content_ratio: ?float
     * }
     */
    public function extractPage(string $absolutePath, int $pageNumber, ?float $deadline = null): array
    {
        $temporaryDirectory = storage_path('app/ai-helper/knowledge-ocr/'.Str::uuid());
        File::ensureDirectoryExists($temporaryDirectory);
        $prefix = $temporaryDirectory.'/page';

        try {
            $render = $this->run([
                (string) config('ai_helper.knowledge_pdftoppm_binary', 'pdftoppm'),
                '-f', (string) $pageNumber,
                '-l', (string) $pageNumber,
                '-r', (string) max(150, (int) config('ai_helper.knowledge_ocr_dpi', 300)),
                '-png',
                '-singlefile',
                $absolutePath,
                $prefix,
            ], $deadline);
            $imagePath = $prefix.'.png';
            if (! $render['successful'] || ! File::exists($imagePath)) {
                return [
                    'attempted' => true,
                    'text' => '',
                    'error' => $render['timed_out'] ? 'document_timeout' : 'render_failed',
                    'has_visual_content' => null,
                    'visual_content_ratio' => null,
                ];
            }

            $visualContentRatio = $this->visualContentRatio($imagePath);
            $hasVisualContent = $visualContentRatio === null
                ? null
                : $visualContentRatio >= max(0.0001, min(0.05, (float) config(
                    'ai_helper.knowledge_visual_content_minimum_ratio',
                    0.0005,
                )));
            if ($deadline !== null && microtime(true) >= $deadline) {
                return [
                    'attempted' => true,
                    'text' => '',
                    'error' => 'document_timeout',
                    'has_visual_content' => $hasVisualContent,
                    'visual_content_ratio' => $visualContentRatio,
                ];
            }

            $ocr = $this->run([
                (string) config('ai_helper.knowledge_tesseract_binary', 'tesseract'),
                $imagePath,
                'stdout',
                '-l', (string) config('ai_helper.knowledge_ocr_languages', 'eng+msa'),
            ], $deadline);

            return [
                'attempted' => true,
                'text' => $ocr['successful'] ? $this->normalizeText($ocr['output']) : '',
                'error' => $ocr['successful'] ? null : ($ocr['timed_out'] ? 'document_timeout' : 'ocr_failed'),
                'has_visual_content' => $hasVisualContent,
                'visual_content_ratio' => $visualContentRatio,
            ];
        } finally {
            File::deleteDirectory($temporaryDirectory);
        }
    }

    /** @return array{successful: bool, output: string, timed_out: bool} */
    private function run(array $command, ?float $deadline = null): array
    {
        try {
            $process = new Process($command);
            $configuredTimeout = max(10, (int) config('ai_helper.knowledge_ocr_timeout_seconds', 120));
            $remaining = $deadline === null ? $configuredTimeout : (int) floor($deadline - microtime(true));
            if ($remaining < 1) {
                return ['successful' => false, 'output' => '', 'timed_out' => true];
            }
            $process->setTimeout(min($configuredTimeout, $remaining));
            $process->run();

            return [
                'successful' => $process->isSuccessful(),
                'output' => $process->getOutput(),
                'timed_out' => false,
            ];
        } catch (Throwable) {
            return [
                'successful' => false,
                'output' => '',
                'timed_out' => $deadline !== null && microtime(true) >= $deadline,
            ];
        }
    }

    private function visualContentRatio(string $imagePath): ?float
    {
        if (! function_exists('imagecreatefrompng')) {
            return null;
        }

        $image = @imagecreatefrompng($imagePath);
        if ($image === false) {
            return null;
        }

        try {
            $width = imagesx($image);
            $height = imagesy($image);
            if ($width < 1 || $height < 1) {
                return null;
            }

            $step = max(1, (int) ceil(max($width, $height) / 500));
            $sampled = 0;
            $nonWhite = 0;
            for ($y = 0; $y < $height; $y += $step) {
                for ($x = 0; $x < $width; $x += $step) {
                    $color = imagecolorat($image, $x, $y);
                    if (imageistruecolor($image)) {
                        $alpha = ($color >> 24) & 0x7F;
                        $red = ($color >> 16) & 0xFF;
                        $green = ($color >> 8) & 0xFF;
                        $blue = $color & 0xFF;
                    } else {
                        $components = imagecolorsforindex($image, $color);
                        $alpha = $components['alpha'];
                        $red = $components['red'];
                        $green = $components['green'];
                        $blue = $components['blue'];
                    }
                    $sampled++;
                    if ($alpha < 120 && min($red, $green, $blue) < 245) {
                        $nonWhite++;
                    }
                }
            }

            return $sampled > 0 ? $nonWhite / $sampled : null;
        } finally {
            imagedestroy($image);
        }
    }

    private function normalizeText(string $text): string
    {
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? '';
        $text = preg_replace("/\R{3,}/", "\n\n", $text) ?? '';

        return trim($text);
    }
}
