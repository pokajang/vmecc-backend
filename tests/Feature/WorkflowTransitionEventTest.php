<?php

namespace Tests\Feature;

use App\Models\Leave;
use App\Models\User;
use App\Models\WorkflowTransitionEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class WorkflowTransitionEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_history_entries_are_copied_to_the_append_only_ledger(): void
    {
        $owner = User::factory()->create();
        $submitted = $this->historyEntry('history-submitted', 'Submitted', $owner);
        $leave = Leave::query()->create([
            'user_id' => $owner->id,
            'display_id' => 'LV-HISTORY-001',
            'leave_type' => 'Annual Leave',
            'status' => 'Pending',
            'start_date' => '2026-07-20',
            'end_date' => '2026-07-20',
            'days' => 1,
            'workflow_stage' => 'review',
            'next_action_role' => 'Human Resource',
            'approval_history' => [$submitted],
            'version' => 1,
        ]);

        $reviewed = $this->historyEntry('history-reviewed', 'Reviewed', $owner);
        $leave->update([
            'workflow_stage' => 'approve',
            'approval_history' => [$submitted, $reviewed],
            'version' => 2,
        ]);

        $events = WorkflowTransitionEvent::query()
            ->where('record_type', Leave::class)
            ->where('record_id', (string) $leave->id)
            ->orderBy('id')
            ->get();

        $this->assertSame(['Submitted', 'Reviewed'], $events->pluck('action')->all());
        $this->assertSame('review', $events->last()->from_stage);
        $this->assertSame('approve', $events->last()->to_stage);
        $this->assertSame((int) $owner->id, $events->last()->actor_user_id);
    }

    public function test_transition_events_reject_updates_and_deletes(): void
    {
        $event = WorkflowTransitionEvent::query()->create([
            'event_uid' => fake()->uuid(),
            'history_entry_id' => fake()->uuid(),
            'record_type' => Leave::class,
            'record_id' => '1',
            'action' => 'Submitted',
            'occurred_at' => now(),
        ]);

        try {
            $event->update(['action' => 'Changed']);
            $this->fail('Updating an append-only transition event should fail.');
        } catch (LogicException) {
            $this->assertSame('Submitted', $event->fresh()->action);
        }

        $this->expectException(LogicException::class);
        $event->delete();
    }

    private function historyEntry(string $id, string $action, User $actor): array
    {
        return [
            'id' => $id,
            'action' => $action,
            'by' => $actor->name,
            'byUserId' => (string) $actor->id,
            'at' => now()->toIso8601String(),
            'remarks' => '',
        ];
    }
}
