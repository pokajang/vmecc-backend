<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_transition_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_uid')->unique();
            $table->string('history_entry_id', 100);
            $table->string('record_type', 100);
            $table->string('record_id', 100);
            $table->string('record_display_id')->nullable();
            $table->string('action');
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->string('from_stage')->nullable();
            $table->string('to_stage')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('actor_name')->nullable();
            $table->string('actor_role')->nullable();
            $table->text('remarks')->nullable();
            $table->json('metadata')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['record_type', 'record_id', 'history_entry_id'], 'workflow_transition_record_history_unique');
            $table->index(['record_type', 'record_id', 'occurred_at'], 'workflow_transition_record_time_idx');
            $table->index(['actor_user_id', 'occurred_at'], 'workflow_transition_actor_time_idx');
        });

        foreach ([
            'leaves' => ['type' => 'App\\Models\\Leave', 'display' => 'display_id'],
            'overtime_records' => ['type' => 'App\\Models\\OvertimeRecord', 'display' => 'display_id'],
            'payroll_claims' => ['type' => 'App\\Models\\PayrollClaim', 'display' => 'display_id'],
            'reports' => ['type' => 'App\\Models\\Report', 'display' => 'display_id'],
        ] as $tableName => $definition) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            DB::table($tableName)
                ->select(['id', $definition['display'], 'status', 'workflow_stage', 'approval_history'])
                ->whereNotNull('approval_history')
                ->orderBy('id')
                ->chunkById(200, function ($records) use ($definition) {
                    foreach ($records as $record) {
                        $history = json_decode((string) $record->approval_history, true);
                        foreach (is_array($history) ? $history : [] as $entry) {
                            if (! is_array($entry)) {
                                continue;
                            }
                            $historyEntryId = trim((string) ($entry['id'] ?? '')) ?: (string) Str::uuid();
                            DB::table('workflow_transition_events')->insertOrIgnore([
                                'event_uid' => (string) Str::uuid(),
                                'history_entry_id' => $historyEntryId,
                                'record_type' => $definition['type'],
                                'record_id' => (string) $record->id,
                                'record_display_id' => trim((string) ($record->{$definition['display']} ?? '')) ?: null,
                                'action' => trim((string) ($entry['action'] ?? 'Unknown')),
                                'from_status' => null,
                                'to_status' => trim((string) ($record->status ?? '')) ?: null,
                                'from_stage' => null,
                                'to_stage' => trim((string) ($record->workflow_stage ?? '')) ?: null,
                                'actor_user_id' => is_numeric($entry['byUserId'] ?? null) ? (int) $entry['byUserId'] : null,
                                'actor_name' => trim((string) ($entry['by'] ?? '')) ?: null,
                                'actor_role' => trim((string) ($entry['actorRole'] ?? '')) ?: null,
                                'remarks' => trim((string) ($entry['remarks'] ?? '')) ?: null,
                                'metadata' => json_encode($entry),
                                'occurred_at' => $entry['at'] ?? now(),
                                'created_at' => now(),
                            ]);
                        }
                    }
                });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_transition_events');
    }
};
