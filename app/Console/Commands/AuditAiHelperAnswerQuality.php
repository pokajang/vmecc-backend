<?php

namespace App\Console\Commands;

use App\Services\AiHelperAnswerQualityAuditService;
use Illuminate\Console\Command;

class AuditAiHelperAnswerQuality extends Command
{
    protected $signature = 'ai-helper:answer-quality:audit
        {--json : Emit machine-readable JSON}';

    protected $description = 'Audit deterministic Ask AI answer quality, workflow grounding, state handling, and clarification.';

    public function handle(AiHelperAnswerQualityAuditService $auditService): int
    {
        $result = $auditService->audit();

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->components->twoColumnDetail('Phase 3 answer-quality contract', $result['ready'] ? '<fg=green>ready</>' : '<fg=red>not ready</>');
            $this->components->twoColumnDetail('Cases passed', $result['cases']['passed'].' / '.$result['cases']['total']);
            $this->components->twoColumnDetail('Workflows covered', $result['workflows']['covered'].' / '.$result['workflows']['registry']);
            foreach ($result['errors'] as $error) {
                $this->components->error($error);
            }
            foreach ($result['failures'] as $failure) {
                $this->components->warn($failure['id'].': '.implode(', ', $failure['failures']));
            }
        }

        return $result['ready'] ? self::SUCCESS : self::FAILURE;
    }
}
