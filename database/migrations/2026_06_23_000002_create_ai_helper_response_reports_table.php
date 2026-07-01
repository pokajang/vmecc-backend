<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_helper_response_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reporter_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('thread_id')->nullable()->constrained('ai_helper_threads')->nullOnDelete();
            $table->foreignId('assistant_message_id')->nullable()->constrained('ai_helper_messages')->nullOnDelete();
            $table->foreignId('preceding_user_message_id')->nullable()->constrained('ai_helper_messages')->nullOnDelete();
            $table->text('reason');
            $table->string('status', 24)->default('new')->index();
            $table->longText('assistant_content')->nullable();
            $table->longText('preceding_user_content')->nullable();
            $table->json('page_context')->nullable();
            $table->json('chat_snapshot')->nullable();
            $table->string('openai_response_id')->nullable();
            $table->string('reporter_ip', 64)->nullable();
            $table->text('reporter_user_agent')->nullable();
            $table->text('admin_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['reporter_user_id', 'created_at']);
            $table->index(['thread_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_helper_response_reports');
    }
};
