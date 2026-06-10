<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

class SystemMaintenanceService
{
    private const SETTINGS_KEY = 'system_maintenance';
    private const CACHE_KEY = 'settings.system_maintenance';
    private const CACHE_TTL_SECONDS = 10;
    private const DEFAULT_MESSAGE = 'System is under maintenance. Please try again later.';
    public const PHASE_OFF = 'off';
    public const PHASE_GRACE = 'grace';
    public const PHASE_ENFORCED = 'enforced';

    public function load(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function () {
            return $this->loadFresh();
        });
    }

    public function save(array $value, ?User $updatedBy = null): array
    {
        $current = $this->loadFresh();
        $normalized = $this->normalizeForSave($value, $current, $updatedBy?->id);

        $this->persist($normalized);
        return $normalized;
    }

    public function resolveState(array $setting): array
    {
        if (! $this->isGraceExpired($setting)) {
            return [
                'setting' => $setting,
                'autoTransitioned' => false,
            ];
        }

        $next = $this->normalizeForSave([
            'enabled' => true,
            'message' => $setting['message'] ?? self::DEFAULT_MESSAGE,
            'phase' => self::PHASE_ENFORCED,
            'graceEndsAt' => null,
        ], $setting, null);

        $this->persist($next);
        return [
            'setting' => $next,
            'autoTransitioned' => true,
        ];
    }

    public function loadFresh(): array
    {
        $setting = Setting::query()->where('key', self::SETTINGS_KEY)->first();
        return $this->normalizeStored($setting?->value ?? []);
    }

    public function default(): array
    {
        return $this->normalizeStored([]);
    }

    public function graceSeconds(): int
    {
        $raw = (int) env('SYSTEM_MAINTENANCE_GRACE_SECONDS', 10);
        return max(5, min(3600, $raw));
    }

    private function persist(array $normalized): void
    {
        Setting::query()->updateOrCreate(
            ['key' => self::SETTINGS_KEY],
            ['value' => $normalized],
        );

        Cache::forget(self::CACHE_KEY);
    }

    private function normalizeStored(mixed $value): array
    {
        $source = is_array($value) ? $value : [];
        $enabled = (bool) ($source['enabled'] ?? false);
        $message = trim((string) ($source['message'] ?? ''));
        if ($message === '') {
            $message = self::DEFAULT_MESSAGE;
        }

        $updatedAt = $this->normalizeIsoString($source['updatedAt'] ?? null);
        $updatedByUserId = $this->normalizeUserId($source['updatedByUserId'] ?? null);
        $graceEndsAt = $this->normalizeIsoString($source['graceEndsAt'] ?? null);
        $sourcePhase = strtolower(trim((string) ($source['phase'] ?? '')));
        $phase = self::PHASE_OFF;

        if ($enabled) {
            if ($sourcePhase === self::PHASE_GRACE && $graceEndsAt) {
                $phase = self::PHASE_GRACE;
            } elseif ($sourcePhase === self::PHASE_ENFORCED) {
                $phase = self::PHASE_ENFORCED;
            } elseif ($graceEndsAt) {
                $phase = $this->isIsoTimestampInFuture($graceEndsAt) ? self::PHASE_GRACE : self::PHASE_ENFORCED;
            } else {
                // Backward compatibility for pre-phase settings: treat enabled as enforced.
                $phase = self::PHASE_ENFORCED;
            }
        }

        if (! $enabled) {
            $graceEndsAt = null;
            $phase = self::PHASE_OFF;
        } elseif ($phase !== self::PHASE_GRACE) {
            $graceEndsAt = null;
        }

        return [
            'enabled' => $enabled,
            'phase' => $phase,
            'graceEndsAt' => $graceEndsAt,
            'message' => $message,
            'updatedAt' => $updatedAt ?: '',
            'updatedByUserId' => $updatedByUserId,
        ];
    }

    private function normalizeForSave(array $value, array $current, ?int $updatedByUserId = null): array
    {
        $enabled = (bool) ($value['enabled'] ?? false);
        $message = trim((string) ($value['message'] ?? ($current['message'] ?? '')));
        if ($message === '') {
            $message = self::DEFAULT_MESSAGE;
        }

        $phaseInput = strtolower(trim((string) ($value['phase'] ?? '')));
        $currentPhase = strtolower(trim((string) ($current['phase'] ?? self::PHASE_OFF)));
        $graceEndsAtInput = $this->normalizeIsoString($value['graceEndsAt'] ?? null);

        $phase = self::PHASE_OFF;
        $graceEndsAt = null;
        if ($enabled) {
            if ($phaseInput === self::PHASE_ENFORCED) {
                $phase = self::PHASE_ENFORCED;
            } elseif ($phaseInput === self::PHASE_GRACE) {
                $phase = self::PHASE_GRACE;
                $graceEndsAt = $graceEndsAtInput ?: now()->addSeconds($this->graceSeconds())->toIso8601String();
            } elseif (($current['enabled'] ?? false) && in_array($currentPhase, [self::PHASE_GRACE, self::PHASE_ENFORCED], true)) {
                $phase = $currentPhase;
                if ($phase === self::PHASE_GRACE) {
                    $graceEndsAt = $this->normalizeIsoString($current['graceEndsAt'] ?? null)
                        ?: now()->addSeconds($this->graceSeconds())->toIso8601String();
                }
            } else {
                $phase = self::PHASE_GRACE;
                $graceEndsAt = now()->addSeconds($this->graceSeconds())->toIso8601String();
            }
        }

        $updatedAt = now()->toIso8601String();

        return [
            'enabled' => $enabled,
            'phase' => $phase,
            'graceEndsAt' => $graceEndsAt,
            'message' => $message,
            'updatedAt' => $updatedAt,
            'updatedByUserId' => $this->normalizeUserId($updatedByUserId ?? ($current['updatedByUserId'] ?? null)),
        ];
    }

    private function normalizeUserId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_numeric($value)) {
            $cast = (int) $value;
            return $cast > 0 ? $cast : null;
        }

        return null;
    }

    private function normalizeIsoString(mixed $value): ?string
    {
        $candidate = trim((string) ($value ?? ''));
        if ($candidate === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($candidate)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    private function isIsoTimestampInFuture(string $isoTimestamp): bool
    {
        try {
            return CarbonImmutable::parse($isoTimestamp)->isFuture();
        } catch (\Throwable) {
            return false;
        }
    }

    private function isGraceExpired(array $setting): bool
    {
        if (! ($setting['enabled'] ?? false)) {
            return false;
        }
        if (($setting['phase'] ?? self::PHASE_OFF) !== self::PHASE_GRACE) {
            return false;
        }

        $graceEndsAt = $this->normalizeIsoString($setting['graceEndsAt'] ?? null);
        if (! $graceEndsAt) {
            return true;
        }

        try {
            return CarbonImmutable::parse($graceEndsAt)->isPast();
        } catch (\Throwable) {
            return true;
        }
    }
}
