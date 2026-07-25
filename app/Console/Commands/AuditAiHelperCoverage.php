<?php

namespace App\Console\Commands;

use App\Services\AiHelperCoverageAuditService;
use Illuminate\Console\Command;

class AuditAiHelperCoverage extends Command
{
    protected $signature = 'ai-helper:coverage:audit
        {--json : Emit machine-readable JSON}';

    protected $description = 'Audit product-wide Ask AI module coverage and representative intent gaps.';

    public function handle(AiHelperCoverageAuditService $auditService): int
    {
        $result = $auditService->audit();

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->components->twoColumnDetail('Coverage contract', $result['phase_1_ready'] ? '<fg=green>ready</>' : '<fg=red>not ready</>');
            $this->components->twoColumnDetail('Modules classified', $result['modules']['classified'].' / '.$result['modules']['catalog']);
            $this->components->twoColumnDetail('System guides', (string) $result['guides']);
            $this->components->twoColumnDetail('Deterministic workflows', (string) $result['workflows']);
            $this->components->twoColumnDetail('Topics represented', $result['topics']['covered'].' / '.$result['topics']['registry']);
            $this->components->twoColumnDetail('Representative queries matched', $result['queries']['matched'].' / '.$result['queries']['total']);
            $this->components->twoColumnDetail('Phase 2 intent contract', $result['phase_2_ready'] ? '<fg=green>ready</>' : '<fg=red>not ready</>');
            $this->components->twoColumnDetail('Phase 2 required', $result['phase_2_required'] ? '<fg=yellow>yes</>' : 'no');

            foreach ($result['errors'] as $error) {
                $this->components->error($error);
            }
            foreach ($result['gap_details'] as $gap) {
                $missing = collect($gap['missing'])
                    ->map(fn (array $values, string $dimension): string => $dimension.'='.implode(',', $values))
                    ->implode('; ');
                $unexpected = collect($gap['unexpected'])
                    ->map(fn (array $values, string $dimension): string => 'unexpected_'.$dimension.'='.implode(',', $values))
                    ->implode('; ');
                $details = collect([$missing, $unexpected])->filter()->implode('; ');
                $this->components->warn($gap['id'].': '.$details);
            }
        }

        return $result['phase_2_ready'] ? self::SUCCESS : self::FAILURE;
    }
}
