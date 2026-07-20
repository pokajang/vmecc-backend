<?php

namespace Tests\Feature;

use App\Jobs\EmbedAiHelperKnowledgeEntry;
use App\Models\AiHelperKnowledgeEntry;
use App\Models\AiHelperMessage;
use App\Models\AiHelperResponseReport;
use App\Models\AiHelperRun;
use App\Models\AiHelperThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class AiHelperOperationsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_stale_stream_reconciliation_aborts_only_old_streaming_assistant_messages(): void
    {
        Carbon::setTestNow('2026-07-20 12:00:00');
        $thread = $this->thread();
        $stale = $this->message($thread, AiHelperMessage::STATUS_STREAMING);
        $recent = $this->message($thread, AiHelperMessage::STATUS_STREAMING);
        $completed = $this->message($thread, AiHelperMessage::STATUS_COMPLETED);
        $staleRun = AiHelperRun::query()->create([
            'user_id' => $thread->user_id,
            'thread_id' => $thread->id,
            'assistant_message_id' => $stale->id,
            'status' => AiHelperRun::STATUS_STARTED,
        ]);
        $recentRun = AiHelperRun::query()->create([
            'user_id' => $thread->user_id,
            'thread_id' => $thread->id,
            'assistant_message_id' => $recent->id,
            'status' => AiHelperRun::STATUS_STARTED,
        ]);
        $this->age('ai_helper_messages', $stale->id, now()->subMinutes(20));
        $this->age('ai_helper_messages', $completed->id, now()->subMinutes(20));

        $this->artisan('ai-helper:reconcile-stale-streams --minutes=15')
            ->expectsOutput('Reconciled 1 stale Ask AI streaming response(s).')
            ->assertSuccessful();

        $this->assertSame(AiHelperMessage::STATUS_ABORTED, $stale->fresh()->status);
        $this->assertSame('AI_HELPER_STREAM_ABANDONED', $stale->fresh()->error);
        $this->assertSame(AiHelperMessage::STATUS_STREAMING, $recent->fresh()->status);
        $this->assertSame(AiHelperMessage::STATUS_COMPLETED, $completed->fresh()->status);
        $this->assertSame(AiHelperRun::STATUS_ABORTED, $staleRun->fresh()->status);
        $this->assertSame('AI_HELPER_STREAM_ABANDONED', $staleRun->fresh()->result_code);
        $this->assertSame(AiHelperRun::STATUS_STARTED, $recentRun->fresh()->status);
    }

    public function test_stuck_embedding_reconciliation_requeues_only_eligible_entries(): void
    {
        Carbon::setTestNow('2026-07-20 12:00:00');
        Queue::fake();
        $eligible = $this->knowledgeEntry();
        $inactive = $this->knowledgeEntry(['active' => false]);
        $recent = $this->knowledgeEntry();
        $this->age('ai_helper_knowledge_entries', $eligible->id, now()->subMinutes(30));
        $this->age('ai_helper_knowledge_entries', $inactive->id, now()->subMinutes(30));

        $this->artisan('ai-helper:reconcile-stuck-embeddings --minutes=20 --retry')
            ->assertSuccessful();

        $this->assertSame('pending', $eligible->fresh()->embedding_status);
        $this->assertSame('failed', $inactive->fresh()->embedding_status);
        $this->assertSame('processing', $recent->fresh()->embedding_status);
        Queue::assertPushed(EmbedAiHelperKnowledgeEntry::class, 1);
    }

    public function test_embedding_job_failure_marks_non_ready_entry_failed_but_preserves_ready_entry(): void
    {
        $entry = $this->knowledgeEntry();
        $job = new EmbedAiHelperKnowledgeEntry($entry->id);
        $job->failed(new RuntimeException('timed out'));

        $this->assertSame('failed', $entry->fresh()->embedding_status);
        $this->assertStringContainsString('timed out', (string) $entry->fresh()->embedding_error);

        $entry->forceFill(['embedding_status' => 'ready', 'embedding_error' => null])->save();
        $job->failed(new RuntimeException('late failure callback'));

        $this->assertSame('ready', $entry->fresh()->embedding_status);
        $this->assertNull($entry->fresh()->embedding_error);
    }

    public function test_runtime_pruning_preserves_open_reports_and_recent_conversations(): void
    {
        Carbon::setTestNow('2026-07-20 12:00:00');
        $user = User::factory()->create();

        $expired = $this->thread($user);
        $this->message($expired, AiHelperMessage::STATUS_COMPLETED);
        $this->age('ai_helper_threads', $expired->id, now()->subDays(100));

        $reported = $this->thread($user);
        $reportedMessage = $this->message($reported, AiHelperMessage::STATUS_COMPLETED);
        $openReport = AiHelperResponseReport::query()->create([
            'reporter_user_id' => $user->id,
            'thread_id' => $reported->id,
            'assistant_message_id' => $reportedMessage->id,
            'reason' => 'Needs review',
            'status' => AiHelperResponseReport::STATUS_NEW,
            'reporter_ip' => '127.0.0.1',
            'reporter_user_agent' => 'phpunit',
        ]);
        $this->age('ai_helper_threads', $reported->id, now()->subDays(100));
        $this->age('ai_helper_response_reports', $openReport->id, now()->subDays(40));

        $closed = $this->thread($user);
        $closedMessage = $this->message($closed, AiHelperMessage::STATUS_COMPLETED);
        $closedReport = AiHelperResponseReport::query()->create([
            'reporter_user_id' => $user->id,
            'thread_id' => $closed->id,
            'assistant_message_id' => $closedMessage->id,
            'reason' => 'Resolved issue',
            'status' => AiHelperResponseReport::STATUS_RESOLVED,
        ]);
        $this->age('ai_helper_threads', $closed->id, now()->subDays(400));
        $this->age('ai_helper_response_reports', $closedReport->id, now()->subDays(400));

        $recent = $this->thread($user);
        $abandoned = $this->message($recent, AiHelperMessage::STATUS_ABORTED, null);
        $this->age('ai_helper_messages', $abandoned->id, now()->subDays(10));

        $oldRun = AiHelperRun::query()->create(['user_id' => $user->id]);
        $recentRun = AiHelperRun::query()->create(['user_id' => $user->id]);
        $this->age('ai_helper_runs', $oldRun->id, now()->subDays(100));

        $this->artisan('ai-helper:prune-runtime-data
                --conversation-days=90
                --failed-message-days=7
                --run-days=90
                --resolved-report-days=365
                --report-network-data-days=30')
            ->assertSuccessful();

        $this->assertDatabaseMissing('ai_helper_threads', ['id' => $expired->id]);
        $this->assertDatabaseMissing('ai_helper_threads', ['id' => $closed->id]);
        $this->assertDatabaseHas('ai_helper_threads', ['id' => $reported->id]);
        $this->assertDatabaseHas('ai_helper_threads', ['id' => $recent->id]);
        $this->assertDatabaseMissing('ai_helper_messages', ['id' => $abandoned->id]);
        $this->assertDatabaseMissing('ai_helper_runs', ['id' => $oldRun->id]);
        $this->assertDatabaseHas('ai_helper_runs', ['id' => $recentRun->id]);
        $this->assertDatabaseMissing('ai_helper_response_reports', ['id' => $closedReport->id]);
        $this->assertDatabaseHas('ai_helper_response_reports', [
            'id' => $openReport->id,
            'reporter_ip' => null,
            'reporter_user_agent' => null,
        ]);
    }

    public function test_storage_health_can_emit_machine_readable_success(): void
    {
        $this->artisan('ai-helper:storage-health
                --json
                --minimum-free-percent=0
                --minimum-free-mb=0
                --maximum-upload-percent=100')
            ->expectsOutputToContain('"ready": true')
            ->assertSuccessful();
    }

    private function thread(?User $user = null): AiHelperThread
    {
        return AiHelperThread::query()->create([
            'user_id' => ($user ?? User::factory()->create())->id,
            'title' => 'Ask AI test',
        ]);
    }

    private function message(
        AiHelperThread $thread,
        string $status,
        ?string $content = 'Answer',
    ): AiHelperMessage {
        return AiHelperMessage::query()->create([
            'thread_id' => $thread->id,
            'role' => AiHelperMessage::ROLE_ASSISTANT,
            'content' => $content,
            'status' => $status,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function knowledgeEntry(array $overrides = []): AiHelperKnowledgeEntry
    {
        return AiHelperKnowledgeEntry::query()->create(array_merge([
            'title' => 'Test guide',
            'content' => '# Test guide',
            'source_mime' => 'text/markdown',
            'source_path' => 'ai-helper/knowledge/test.md',
            'status' => AiHelperKnowledgeEntry::STATUS_ACTIVE,
            'embedding_status' => 'processing',
            'active' => true,
        ], $overrides));
    }

    private function age(string $table, int $id, Carbon $at): void
    {
        DB::table($table)->where('id', $id)->update([
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }
}
