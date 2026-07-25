<?php

namespace App\Console\Commands;

use App\Services\AiHelperInputSafetyAuditService;
use Illuminate\Console\Command;

class AuditAiHelperInputSafety extends Command
{
    protected $signature = 'ai-helper:input-safety:audit
        {--json : Emit machine-readable JSON}';

    protected $description = 'Audit Ask AI sensitive-input, restricted-request, recoverability, and query-quality decisions.';

    public function handle(AiHelperInputSafetyAuditService $auditService): int
    {
        $result = $auditService->audit();

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->components->twoColumnDetail('Phase 4A input-safety contract', $result['ready'] ? '<fg=green>ready</>' : '<fg=red>not ready</>');
            $this->components->twoColumnDetail('Cases passed', $result['cases']['passed'].' / '.$result['cases']['total']);
            $this->components->twoColumnDetail('Decisions covered', count($result['decisions']['covered']).' / '.count($result['decisions']['required']));
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
