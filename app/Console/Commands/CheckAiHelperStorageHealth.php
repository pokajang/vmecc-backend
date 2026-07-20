<?php

namespace App\Console\Commands;

use App\Services\AiHelperStorageCapacityService;
use Illuminate\Console\Command;

class CheckAiHelperStorageHealth extends Command
{
    protected $signature = 'ai-helper:storage-health
        {--json : Emit machine-readable JSON}
        {--minimum-free-percent= : Minimum filesystem free-space percentage}
        {--minimum-free-mb= : Minimum filesystem free space in MiB}
        {--maximum-upload-percent= : Maximum allowed use of either configured Ask AI upload quota}';

    protected $description = 'Check shared-host filesystem headroom and configured Ask AI upload quota usage.';

    public function handle(AiHelperStorageCapacityService $capacity): int
    {
        $minimumFreePercent = $this->boundedNumberOption(
            'minimum-free-percent',
            (float) config('ai_helper.storage_minimum_free_percent', 20),
            0,
            100,
        );
        $minimumFreeMb = $this->boundedNumberOption(
            'minimum-free-mb',
            (float) config('ai_helper.storage_minimum_free_mb', 1024),
            0,
            PHP_INT_MAX,
        );
        $maximumUploadPercent = $this->boundedNumberOption(
            'maximum-upload-percent',
            (float) config('ai_helper.storage_maximum_upload_percent', 85),
            1,
            100,
        );
        if ($minimumFreePercent === null || $minimumFreeMb === null || $maximumUploadPercent === null) {
            return self::INVALID;
        }

        return $this->render($capacity->status([
            'minimum_free_percent' => $minimumFreePercent,
            'minimum_free_bytes' => (int) round($minimumFreeMb * 1024 * 1024),
            'maximum_upload_percent' => $maximumUploadPercent,
        ]));
    }

    private function boundedNumberOption(string $name, float $default, float $minimum, float $maximum): ?float
    {
        $value = $this->option($name);
        if ($value === null || $value === '') {
            return max($minimum, min($maximum, $default));
        }
        if (! is_numeric($value) || (float) $value < $minimum || (float) $value > $maximum) {
            $this->error("The --{$name} option must be between {$minimum} and {$maximum}.");

            return null;
        }

        return (float) $value;
    }

    /** @param array<string, mixed> $payload */
    private function render(array $payload): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('Ask AI storage: '.(($payload['ready'] ?? false) ? 'READY' : 'NOT READY'));
            if (isset($payload['filesystem'], $payload['uploads'])) {
                $this->line(sprintf(
                    'Filesystem free: %.2f%% (%s MiB); projected: %.2f%% (%s MiB)',
                    (float) $payload['filesystem']['free_percent'],
                    number_format((float) $payload['filesystem']['free_bytes'] / 1024 / 1024, 0),
                    (float) $payload['filesystem']['projected_free_percent'],
                    number_format((float) $payload['filesystem']['projected_free_bytes'] / 1024 / 1024, 0),
                ));
                foreach (['documents', 'knowledge'] as $type) {
                    $usage = $payload['uploads'][$type];
                    $used = number_format((float) $usage['used_bytes'] / 1024 / 1024, 2);
                    $percentage = $usage['used_percent'] === null ? 'unlimited' : $usage['used_percent'].'%';
                    $this->line(ucfirst($type)." uploads: {$used} MiB ({$percentage})");
                }
            }
            if (isset($payload['error'])) {
                $this->error((string) $payload['error']);
            }
        }

        return ($payload['ready'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
