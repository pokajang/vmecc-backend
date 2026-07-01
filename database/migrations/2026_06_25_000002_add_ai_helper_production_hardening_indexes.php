<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_helper_knowledge_entries', function (Blueprint $table) {
            $table->index([
                'scope_type',
                'module_key',
                'route_key',
                'active',
                'status',
                'review_status',
                'visibility',
            ], 'ai_helper_knowledge_context_status_idx');
        });

        Schema::table('ai_helper_knowledge_chunks', function (Blueprint $table) {
            $table->index([
                'route_key',
                'module_key',
                'active',
                'updated_at',
            ], 'ai_helper_chunks_context_updated_idx');
        });

        Schema::table('ai_helper_response_reports', function (Blueprint $table) {
            $table->index(['status', 'created_at'], 'ai_helper_reports_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('ai_helper_response_reports', function (Blueprint $table) {
            $table->dropIndex('ai_helper_reports_status_created_idx');
        });

        Schema::table('ai_helper_knowledge_chunks', function (Blueprint $table) {
            $table->dropIndex('ai_helper_chunks_context_updated_idx');
        });

        Schema::table('ai_helper_knowledge_entries', function (Blueprint $table) {
            $table->dropIndex('ai_helper_knowledge_context_status_idx');
        });
    }
};
