<?php

namespace App\Console\Commands;

use App\Models\AiHelperKnowledgeEntry;
use Illuminate\Console\Command;

class CheckAiHelperKnowledgeReadiness extends Command
{
    protected $signature = 'ai-helper:knowledge-readiness {--json : Emit machine-readable JSON}';

    protected $description = 'Check the private Markdown knowledge corpus readiness.';

    public function handle(): int
    {
        $knowledgeQuery = AiHelperKnowledgeEntry::query()
            ->where('source_mime', 'text/markdown')
            ->whereNotIn('status', [
                AiHelperKnowledgeEntry::STATUS_DELETING,
                AiHelperKnowledgeEntry::STATUS_DELETED,
            ]);
        $counts = [
            'markdown_sources' => (clone $knowledgeQuery)->count(),
            'active' => (clone $knowledgeQuery)->where('active', true)->count(),
            'processing' => (clone $knowledgeQuery)->where('status', AiHelperKnowledgeEntry::STATUS_PROCESSING)->count(),
            'failed' => (clone $knowledgeQuery)->where('status', AiHelperKnowledgeEntry::STATUS_FAILED)->count(),
        ];
        $ready = $counts['processing'] === 0 && $counts['failed'] === 0;
        $payload = [
            'ready' => $ready,
            'runtime' => [
                'mode' => 'markdown_only',
                'pdf_ingestion_enabled' => false,
                'external_ocr_required' => false,
            ],
            'knowledge' => $counts,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        } else {
            $this->components->twoColumnDetail('Release ready', $ready ? '<fg=green>yes</>' : '<fg=red>no</>');
            $this->components->twoColumnDetail('Knowledge mode', 'Markdown only (PDF ingestion disabled)');
            foreach ($counts as $label => $count) {
                $this->components->twoColumnDetail(str_replace('_', ' ', ucfirst($label)), (string) $count);
            }
        }

        return $ready ? self::SUCCESS : self::FAILURE;
    }
}
