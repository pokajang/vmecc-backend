<?php

namespace App\Services;

use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class AiHelperKnowledgeRuntimeService
{
    /**
     * @return array{
     *     ready: bool,
     *     queue_ready: bool,
     *     queue_connection: string,
     *     queue_driver: string,
     *     queue_retry_after: int,
     *     job_timeout: int,
     *     tools: array<string, bool>,
     *     missing_languages: array<int, string>
     * }
     */
    public function diagnostics(): array
    {
        $ocrEnabled = (bool) config('ai_helper.knowledge_ocr_enabled', true);
        $tools = [
            'pdftotext' => $this->commandWorks((string) config('ai_helper.knowledge_pdftotext_binary', 'pdftotext'), ['-v']),
            'pdfinfo' => $this->commandWorks((string) config('ai_helper.knowledge_pdfinfo_binary', 'pdfinfo'), ['-v']),
            'pdftoppm' => ! $ocrEnabled || $this->commandWorks((string) config('ai_helper.knowledge_pdftoppm_binary', 'pdftoppm'), ['-v']),
            'tesseract' => ! $ocrEnabled || $this->commandWorks((string) config('ai_helper.knowledge_tesseract_binary', 'tesseract'), ['--version']),
            'gd' => ! $ocrEnabled || function_exists('imagecreatefrompng'),
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
        $queueConnection = (string) config('queue.default', 'sync');
        $queueDriver = (string) config("queue.connections.{$queueConnection}.driver", $queueConnection);
        $queueRetryAfter = (int) config("queue.connections.{$queueConnection}.retry_after", 0);
        $jobTimeout = max(60, (int) config('ai_helper.knowledge_job_timeout_seconds', 900));
        $requiresAsyncQueue = app()->environment('production')
            && (bool) config('ai_helper.knowledge_require_async_queue', true);
        $requiresRetryWindow = in_array($queueDriver, ['database', 'redis', 'beanstalkd'], true);
        $queueReady = (! $requiresAsyncQueue || $queueDriver !== 'sync')
            && (! $requiresRetryWindow || $queueRetryAfter > $jobTimeout);

        return [
            'ready' => $queueReady && ! in_array(false, $tools, true) && $missingLanguages === [],
            'queue_ready' => $queueReady,
            'queue_connection' => $queueConnection,
            'queue_driver' => $queueDriver,
            'queue_retry_after' => $queueRetryAfter,
            'job_timeout' => $jobTimeout,
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
            $problems[] = 'an asynchronous queue connection with retry_after greater than the knowledge job timeout';
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
