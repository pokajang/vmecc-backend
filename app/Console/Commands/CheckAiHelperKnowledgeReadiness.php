<?php

namespace App\Console\Commands;

use App\Models\AiHelperKnowledgeEntry;
use App\Services\AiHelperKnowledgeRuntimeService;
use Illuminate\Console\Command;

class CheckAiHelperKnowledgeReadiness extends Command
{
    protected $signature = 'ai-helper:knowledge-readiness {--json : Emit machine-readable JSON}';

    protected $description = 'Check Ask AI PDF runtime, migration/re-index coverage, and document quality release gates.';

    public function handle(AiHelperKnowledgeRuntimeService $runtime): int
    {
        $runtimeDiagnostics = $runtime->diagnostics();
        $pdfQuery = AiHelperKnowledgeEntry::query()
            ->where('source_mime', 'application/pdf')
            ->whereNotIn('status', [
                AiHelperKnowledgeEntry::STATUS_DELETING,
                AiHelperKnowledgeEntry::STATUS_DELETED,
            ]);
        $counts = [
            'pdf_documents' => (clone $pdfQuery)->count(),
            'active' => (clone $pdfQuery)->where('active', true)->count(),
            'pending_reindex' => (clone $pdfQuery)->whereNull('quality_status')->count(),
            'processing' => (clone $pdfQuery)->where('status', AiHelperKnowledgeEntry::STATUS_PROCESSING)->count(),
            'failed' => (clone $pdfQuery)->where('status', AiHelperKnowledgeEntry::STATUS_FAILED)->count(),
            'review_required' => (clone $pdfQuery)->where('quality_status', 'review_required')->count(),
        ];
        $ready = $runtimeDiagnostics['ready']
            && $counts['pending_reindex'] === 0
            && $counts['processing'] === 0
            && $counts['failed'] === 0
            && $counts['review_required'] === 0;
        $payload = [
            'ready' => $ready,
            'runtime' => $runtimeDiagnostics,
            'knowledge' => $counts,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        } else {
            $this->components->twoColumnDetail('Release ready', $ready ? '<fg=green>yes</>' : '<fg=red>no</>');
            $this->components->twoColumnDetail('Runtime ready', $runtimeDiagnostics['ready'] ? 'yes' : 'no');
            $this->components->twoColumnDetail('Queue', sprintf(
                '%s (%s), retry_after=%d, job_timeout=%d',
                $runtimeDiagnostics['queue_connection'],
                $runtimeDiagnostics['queue_driver'],
                $runtimeDiagnostics['queue_retry_after'],
                $runtimeDiagnostics['job_timeout'],
            ));
            $this->components->twoColumnDetail(
                'Missing OCR languages',
                $runtimeDiagnostics['missing_languages'] === []
                    ? 'none'
                    : implode(', ', $runtimeDiagnostics['missing_languages']),
            );
            foreach ($counts as $label => $count) {
                $this->components->twoColumnDetail(str_replace('_', ' ', ucfirst($label)), (string) $count);
            }
        }

        return $ready ? self::SUCCESS : self::FAILURE;
    }
}
