<?php

namespace App\Services;

use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class AiHelperKnowledgeRuntimeService
{
    /** @return array{ready: bool, queue_ready: bool, tools: array<string, bool>, missing_languages: array<int, string>} */
    public function diagnostics(): array
    {
        $ocrEnabled = (bool) config('ai_helper.knowledge_ocr_enabled', true);
        $tools = [
            'pdftotext' => $this->commandWorks((string) config('ai_helper.knowledge_pdftotext_binary', 'pdftotext'), ['-v']),
            'pdfinfo' => $this->commandWorks((string) config('ai_helper.knowledge_pdfinfo_binary', 'pdfinfo'), ['-v']),
            'pdftoppm' => ! $ocrEnabled || $this->commandWorks((string) config('ai_helper.knowledge_pdftoppm_binary', 'pdftoppm'), ['-v']),
            'tesseract' => ! $ocrEnabled || $this->commandWorks((string) config('ai_helper.knowledge_tesseract_binary', 'tesseract'), ['--version']),
        ];
        $installedLanguages = $ocrEnabled ? $this->tesseractLanguages() : [];
        $requiredLanguages = collect(explode('+', (string) config('ai_helper.knowledge_ocr_languages', 'eng+msa')))
            ->map(fn (string $language) => trim($language))
            ->filter()
            ->values()
            ->all();
        $missingLanguages = $ocrEnabled && $tools['tesseract']
            ? array_values(array_diff($requiredLanguages, $installedLanguages))
            : ($ocrEnabled ? $requiredLanguages : []);
        $queueReady = ! app()->environment('production')
            || ! (bool) config('ai_helper.knowledge_require_async_queue', true)
            || config('queue.default') !== 'sync';

        return [
            'ready' => $queueReady && ! in_array(false, $tools, true) && $missingLanguages === [],
            'queue_ready' => $queueReady,
            'tools' => $tools,
            'missing_languages' => $missingLanguages,
        ];
    }

    public function assertPdfIngestionReady(): void
    {
        $diagnostics = $this->diagnostics();
        if ($diagnostics['ready']) {
            return;
        }

        $problems = [];
        if (! $diagnostics['queue_ready']) {
            $problems[] = 'an asynchronous queue connection';
        }
        foreach ($diagnostics['tools'] as $tool => $available) {
            if (! $available) {
                $problems[] = $tool;
            }
        }
        if ($diagnostics['missing_languages'] !== []) {
            $problems[] = 'Tesseract language data: '.implode(', ', $diagnostics['missing_languages']);
        }

        throw new RuntimeException('PDF knowledge ingestion is unavailable. Configure '.implode(', ', $problems).'.');
    }

    private function commandWorks(string $binary, array $arguments): bool
    {
        try {
            $process = new Process([$binary, ...$arguments]);
            $process->setTimeout(10);
            $process->run();

            return $process->isSuccessful();
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<int, string> */
    private function tesseractLanguages(): array
    {
        try {
            $process = new Process([(string) config('ai_helper.knowledge_tesseract_binary', 'tesseract'), '--list-langs']);
            $process->setTimeout(10);
            $process->run();
            if (! $process->isSuccessful()) {
                return [];
            }

            return collect(preg_split('/\R/', $process->getOutput()."\n".$process->getErrorOutput()) ?: [])
                ->map(fn (string $line) => trim($line))
                ->filter(fn (string $line) => $line !== '' && ! Str::startsWith($line, 'List of available languages'))
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }
}
