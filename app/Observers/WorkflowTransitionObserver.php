<?php

namespace App\Observers;

use App\Models\WorkflowTransitionEvent;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class WorkflowTransitionObserver
{
    public function saved(Model $record): void
    {
        if (! Schema::hasTable('workflow_transition_events')) {
            return;
        }

        $current = is_array($record->approval_history ?? null) ? $record->approval_history : [];
        $previous = $this->decodeHistory($record->getRawOriginal('approval_history'));
        $previousIds = collect($previous)
            ->map(fn ($entry) => is_array($entry) ? trim((string) ($entry['id'] ?? '')) : '')
            ->filter()
            ->flip();

        foreach ($current as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $historyEntryId = trim((string) ($entry['id'] ?? ''));
            if ($historyEntryId === '' || $previousIds->has($historyEntryId)) {
                continue;
            }

            WorkflowTransitionEvent::query()->firstOrCreate([
                'record_type' => $record->getMorphClass(),
                'record_id' => (string) $record->getKey(),
                'history_entry_id' => $historyEntryId,
            ], [
                'event_uid' => (string) Str::uuid(),
                'record_display_id' => trim((string) ($record->display_id ?? $record->report_uid ?? '')) ?: null,
                'action' => trim((string) ($entry['action'] ?? 'Unknown')),
                'from_status' => $this->originalValue($record, 'status'),
                'to_status' => $this->currentValue($record, 'status'),
                'from_stage' => $this->originalValue($record, 'workflow_stage'),
                'to_stage' => $this->currentValue($record, 'workflow_stage'),
                'actor_user_id' => is_numeric($entry['byUserId'] ?? null) ? (int) $entry['byUserId'] : null,
                'actor_name' => trim((string) ($entry['by'] ?? '')) ?: null,
                'actor_role' => trim((string) ($entry['actorRole'] ?? '')) ?: null,
                'remarks' => trim((string) ($entry['remarks'] ?? '')) ?: null,
                'metadata' => $entry,
                'occurred_at' => $this->occurredAt($entry['at'] ?? null),
            ]);
        }
    }

    private function decodeHistory(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function originalValue(Model $record, string $key): ?string
    {
        $value = trim((string) ($record->getRawOriginal($key) ?? ''));

        return $value !== '' ? $value : null;
    }

    private function currentValue(Model $record, string $key): ?string
    {
        $value = trim((string) ($record->getAttribute($key) ?? ''));

        return $value !== '' ? $value : null;
    }

    private function occurredAt(mixed $value): Carbon
    {
        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return now();
        }
    }
}
