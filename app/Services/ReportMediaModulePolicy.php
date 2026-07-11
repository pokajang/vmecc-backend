<?php

namespace App\Services;

final class ReportMediaModulePolicy
{
    public function normalize(mixed $module): string
    {
        return strtolower(trim((string) $module));
    }

    public function isSupported(mixed $module): bool
    {
        return array_key_exists($this->normalize($module), $this->modules());
    }

    public function isUploadEnabled(mixed $module): bool
    {
        $module = $this->normalize($module);

        return $this->isSupported($module)
            && (bool) ($this->modules()[$module]['upload_enabled'] ?? false);
    }

    public function permissionFor(mixed $module): ?string
    {
        $permission = trim((string) ($this->modules()[$this->normalize($module)]['permission'] ?? ''));

        return $permission !== '' ? $permission : null;
    }

    /**
     * @return array<int, string>
     */
    public function uploadEnabledModules(): array
    {
        return array_values(array_filter(
            array_keys($this->modules()),
            fn (string $module): bool => $this->isUploadEnabled($module),
        ));
    }

    /**
     * @return array<string, array{permission?: string, upload_enabled?: bool}>
     */
    private function modules(): array
    {
        $modules = config('report_media.modules', []);

        return is_array($modules) ? $modules : [];
    }
}
