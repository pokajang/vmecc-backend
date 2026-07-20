<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_helper_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_uuid')->nullable()->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('thread_id')->nullable()->constrained('ai_helper_threads')->nullOnDelete();
            $table->foreignId('assistant_message_id')->nullable()->constrained('ai_helper_messages')->nullOnDelete();
            $table->string('surface', 32)->default('chat');
            $table->string('pipeline_version', 24)->nullable();
            $table->string('index_version', 64)->nullable();
            $table->string('status', 24)->default('started')->index();
            $table->string('result_code', 64)->nullable()->index();
            $table->string('intent', 32)->nullable();
            $table->string('language', 16)->nullable();
            $table->json('topic_keys')->nullable();
            $table->unsignedSmallInteger('candidate_documents')->default(0);
            $table->unsignedSmallInteger('candidate_chunks')->default(0);
            $table->unsignedSmallInteger('evidence_sources')->default(0);
            $table->boolean('retrieval_recovered')->default(false);
            $table->boolean('semantic_fallback')->default(false);
            $table->boolean('rerank_fallback')->default(false);
            $table->string('verification_status', 24)->nullable();
            $table->unsignedTinyInteger('verification_attempts')->default(0);
            $table->unsignedSmallInteger('provider_calls')->default(0);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('stage_timings_ms')->nullable();
            $table->json('provider_request_ids')->nullable();
            $table->string('error_stage', 32)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at'], 'ai_helper_runs_user_created_idx');
            $table->index(['status', 'created_at'], 'ai_helper_runs_status_created_idx');
            $table->index(['pipeline_version', 'created_at'], 'ai_helper_runs_pipeline_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_helper_runs');
    }
};
