<?php

namespace App\Services;

final class AiHelperWorkflowRenderer
{
    public function render(array $workflow, bool $malay, array $uiState = []): string
    {
        $steps = collect($workflow['steps'] ?? [])->values()->map(
            fn (array $step, int $index): string => ($index + 1).'. '.$this->renderStep($step, $malay),
        );
        $type = $this->localizedTitle($workflow, $malay);
        $intro = $malay
            ? "Untuk **{$type}**, ikut langkah berikut:"
            : "To complete **{$type}**, follow these steps:";
        $next = $this->nextStep($workflow, $uiState, $malay);
        $closing = $next ?? ($malay
            ? 'Pilihan yang dipaparkan bergantung pada akses dan keadaan rekod anda.'
            : 'The options shown depend on your access and the current record state.');

        return $intro."\n\n".$steps->join("\n")."\n\n{$closing}";
    }

    private function renderStep(array $step, bool $malay): string
    {
        $target = $this->bold((string) ($step['target'] ?? ''));
        $targets = $this->targetList((array) ($step['targets'] ?? []), $malay);

        return match ($step['kind'] ?? '') {
            'open_menu' => $malay ? "Buka menu {$target}." : "Open the {$target} menu.",
            'select' => $malay ? "Pilih {$target}." : "Select {$target}.",
            'choose' => $malay ? "Pilih salah satu: {$targets}." : "Choose one: {$targets}.",
            'branch' => $malay ? "Untuk {$target}, lengkapkan {$targets}." : "For {$target}, complete {$targets}.",
            'branch_choose' => $malay ? "Untuk {$target}, pilih tindakan yang diperlukan: {$targets}." : "For {$target}, choose the required action: {$targets}.",
            'complete' => $malay ? "Lengkapkan {$targets}." : "Complete {$targets}.",
            'review' => $malay ? "Semak butiran, kemudian gunakan {$targets}." : "Review the details, then use {$targets}.",
            'verify' => $malay ? "Buka semula rekod dan sahkan {$targets}." : "Reopen the record and verify {$targets}.",
            default => $malay ? "Teruskan pada {$target}." : "Continue at {$target}.",
        };
    }

    private function nextStep(array $workflow, array $uiState, bool $malay): ?string
    {
        if (($uiState['record_status'] ?? null) === 'Submitted') {
            return $malay
                ? 'Rekod ini berstatus **Submitted**. Buka semula rekod dan gunakan hanya tindakan yang dipaparkan untuk akses anda.'
                : 'This record is **Submitted**. Reopen it and use only the actions displayed for your access.';
        }

        $missing = collect($uiState['missing_fields'] ?? [])->filter()->values();
        if ($missing->isNotEmpty()) {
            $fields = $this->targetList($missing->all(), $malay);

            return $malay ? "Seterusnya, lengkapkan {$fields}." : "Next, complete {$fields}.";
        }

        $actions = collect($uiState['available_actions'] ?? [])->filter()->values();
        if ($actions->isNotEmpty()) {
            $action = $this->bold((string) $actions->first());

            return $malay ? "Tindakan seterusnya yang tersedia ialah {$action}." : "The next available action is {$action}.";
        }

        $currentStep = (string) ($uiState['current_step'] ?? '');
        if ($currentStep === '') {
            return null;
        }
        $steps = array_values($workflow['steps'] ?? []);
        foreach ($steps as $index => $step) {
            if (($step['key'] ?? null) === $currentStep && isset($steps[$index + 1])) {
                $rendered = $this->renderStep($steps[$index + 1], $malay);

                return $malay ? "Seterusnya: {$rendered}" : "Next: {$rendered}";
            }
        }

        return null;
    }

    private function targetList(array $targets, bool $malay): string
    {
        $values = collect($targets)
            ->map(fn ($target) => $this->bold((string) $target))
            ->filter()
            ->values();
        $connector = $malay ? ' dan ' : ' and ';

        return match ($values->count()) {
            0 => '',
            1 => (string) $values->first(),
            2 => $values->join($connector),
            default => $values->slice(0, -1)->join(', ').','.$connector.$values->last(),
        };
    }

    private function bold(string $value): string
    {
        $value = trim($value);

        return $value === '' ? '' : "**{$value}**";
    }

    private function localizedTitle(array $workflow, bool $malay): string
    {
        $fallback = (string) ($workflow['type'] ?? $workflow['action'] ?? 'Workflow');
        if (! $malay) {
            return $fallback;
        }

        return match ($workflow['key'] ?? '') {
            'leave.self_service' => 'memohon cuti',
            'overtime.self_service' => 'memohon kerja lebih masa',
            'payroll.payslip.view' => 'melihat slip gaji',
            'payroll.claim.submit' => 'membuat tuntutan',
            'roster.manage' => 'membuat dan menerbitkan roster',
            'teams.manage' => 'membuat atau mengurus pasukan',
            'reports.navigate' => 'membuka atau membuat laporan',
            'users.manage' => 'mengurus akaun pengguna',
            'roles.permissions.manage' => 'mengurus kebenaran peranan',
            'settings.module_activation' => 'mengurus pengaktifan modul',
            default => "menjalankan {$fallback}",
        };
    }
}
