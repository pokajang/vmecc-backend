<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_helper_runs', function (Blueprint $table) {
            $table->index('created_at', 'ai_helper_runs_created_idx');
        });

        Schema::table('ai_helper_messages', function (Blueprint $table) {
            $table->index(['role', 'status', 'updated_at'], 'ai_helper_messages_stale_idx');
        });

        Schema::table('ai_helper_knowledge_entries', function (Blueprint $table) {
            $table->index(['embedding_status', 'updated_at'], 'ai_helper_embeddings_stale_idx');
        });

        Schema::table('ai_helper_response_reports', function (Blueprint $table) {
            $table->index('created_at', 'ai_helper_reports_created_idx');
            $table->index(['status', 'updated_at'], 'ai_helper_reports_status_updated_idx');
        });

        Schema::table('ai_helper_threads', function (Blueprint $table) {
            $table->index('updated_at', 'ai_helper_threads_updated_idx');
        });
    }

    public function down(): void
    {
        Schema::table('ai_helper_threads', function (Blueprint $table) {
            $table->dropIndex('ai_helper_threads_updated_idx');
        });

        Schema::table('ai_helper_response_reports', function (Blueprint $table) {
            $table->dropIndex('ai_helper_reports_created_idx');
            $table->dropIndex('ai_helper_reports_status_updated_idx');
        });

        Schema::table('ai_helper_knowledge_entries', function (Blueprint $table) {
            $table->dropIndex('ai_helper_embeddings_stale_idx');
        });

        Schema::table('ai_helper_messages', function (Blueprint $table) {
            $table->dropIndex('ai_helper_messages_stale_idx');
        });

        Schema::table('ai_helper_runs', function (Blueprint $table) {
            $table->dropIndex('ai_helper_runs_created_idx');
        });
    }
};
