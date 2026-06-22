<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\QueryException;

class ModuleActivationService
{
    public const SETTING_KEY = 'module_activation';

    public function load(): array
    {
        if ($this->forceAllEnabled()) {
            return $this->payload([], true);
        }

        try {
            $setting = Setting::query()->where('key', self::SETTING_KEY)->first();
            $raw = $setting?->value;
        } catch (QueryException) {
            return $this->payload([], true);
        }

        if (! is_array($raw)) {
            return $this->payload([], true);
        }

        $configured = $this->normalizeConfigured($raw['configured'] ?? $raw);
        return $this->payload($configured, false);
    }

    public function save(array $configured, ?User $actor = null): array
    {
        $normalized = $this->normalizeConfigured($configured);
        foreach (ModuleCatalog::lockedKeys() as $lockedKey) {
            unset($normalized[$lockedKey]);
        }

        $value = [
            'configured' => $normalized,
            'updatedAt' => now()->toIso8601String(),
            'updatedByUserId' => $actor?->id,
            'updatedBy' => $actor?->name,
        ];

        Setting::query()->updateOrCreate(
            ['key' => self::SETTING_KEY],
            ['value' => $value],
        );

        return $this->payload($normalized, false);
    }

    public function isEnabled(string $key): bool
    {
        if ($this->forceAllEnabled()) {
            return true;
        }

        $payload = $this->load();
        $effective = $payload['effective'][$key] ?? null;
        if (! is_array($effective)) {
            return true;
        }

        return (bool) ($effective['enabled'] ?? true);
    }

    public function effectiveState(string $key): array
    {
        $payload = $this->load();
        $effective = $payload['effective'][$key] ?? null;
        if (is_array($effective)) {
            return $effective;
        }

        return [
            'enabled' => true,
            'reason' => null,
            'blockingModule' => null,
        ];
    }

    public function normalizeConfigured(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $configured = [];
        foreach ($raw as $key => $value) {
            $key = trim((string) $key);
            if ($key === '' || ! ModuleCatalog::has($key)) {
                continue;
            }

            if (in_array($key, ModuleCatalog::lockedKeys(), true)) {
                continue;
            }

            if (is_bool($value)) {
                $configured[$key] = $value;
                continue;
            }

            if (is_array($value) && array_key_exists('enabled', $value) && is_bool($value['enabled'])) {
                $configured[$key] = $value['enabled'];
            }
        }

        return $configured;
    }

    public function payload(array $configured, bool $fallbackMode = false): array
    {
        $effective = [];

        foreach (ModuleCatalog::keys() as $key) {
            $effective[$key] = $this->resolveEffectiveState($key, $configured);
        }

        return [
            'registry' => ModuleCatalog::registryPayload(),
            'configured' => $configured,
            'effective' => $effective,
            'forceAllEnabled' => $this->forceAllEnabled(),
            'fallbackMode' => $fallbackMode,
        ];
    }

    private function resolveEffectiveState(string $key, array $configured, array $seen = []): array
    {
        if ($this->forceAllEnabled()) {
            return [
                'enabled' => true,
                'reason' => null,
                'blockingModule' => null,
            ];
        }

        if (! ModuleCatalog::has($key)) {
            return [
                'enabled' => true,
                'reason' => null,
                'blockingModule' => null,
            ];
        }

        if (isset($seen[$key])) {
            return [
                'enabled' => true,
                'reason' => null,
                'blockingModule' => null,
            ];
        }

        $seen[$key] = true;
        $module = ModuleCatalog::MODULES[$key];

        if ((bool) ($module['locked'] ?? false)) {
            return [
                'enabled' => true,
                'reason' => null,
                'blockingModule' => null,
            ];
        }

        if (array_key_exists($key, $configured) && $configured[$key] === false) {
            return [
                'enabled' => false,
                'reason' => 'configured_disabled',
                'blockingModule' => $key,
            ];
        }

        $parent = $module['parent'] ?? null;
        if ($parent !== null) {
            $parentState = $this->resolveEffectiveState($parent, $configured, $seen);
            if (! ($parentState['enabled'] ?? true)) {
                return [
                    'enabled' => false,
                    'reason' => 'parent_disabled',
                    'blockingModule' => $parentState['blockingModule'] ?? $parent,
                ];
            }
        }

        foreach (($module['dependencies'] ?? []) as $dependency) {
            $dependencyState = $this->resolveEffectiveState($dependency, $configured, $seen);
            if (! ($dependencyState['enabled'] ?? true)) {
                return [
                    'enabled' => false,
                    'reason' => 'dependency_disabled',
                    'blockingModule' => $dependencyState['blockingModule'] ?? $dependency,
                ];
            }
        }

        return [
            'enabled' => true,
            'reason' => null,
            'blockingModule' => null,
        ];
    }

    private function forceAllEnabled(): bool
    {
        return (bool) config('features.module_activation_force_all_enabled', false);
    }
}
