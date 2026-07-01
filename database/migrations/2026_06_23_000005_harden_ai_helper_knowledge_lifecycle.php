<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_helper_knowledge_entries', function (Blueprint $table) {
            $table->string('visibility', 16)->default('shared')->after('scope_type')->index();
            $table->string('review_status', 24)->default('approved')->after('status')->index();
            $table->foreignId('reviewed_by')->nullable()->after('review_status')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->text('review_note')->nullable()->after('reviewed_at');
            $table->timestamp('processed_at')->nullable()->after('acknowledged_at');
            $table->string('content_hash', 64)->nullable()->after('source_path')->index();

            $table->index(['visibility', 'review_status', 'active', 'status'], 'ai_helper_knowledge_use_idx');
            $table->index(['review_status', 'created_at'], 'ai_helper_knowledge_review_idx');
        });

        DB::table('ai_helper_knowledge_entries')
            ->whereNotNull('uploaded_by')
            ->update([
                'visibility' => 'personal',
                'review_status' => 'approved',
            ]);

        DB::table('ai_helper_knowledge_entries')
            ->whereNull('uploaded_by')
            ->update([
                'visibility' => 'shared',
                'review_status' => 'approved',
            ]);

        Schema::create('ai_helper_knowledge_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_entry_id')->constrained('ai_helper_knowledge_entries')->cascadeOnDelete();
            $table->unsignedInteger('chunk_index');
            $table->text('content');
            $table->string('content_hash', 64);
            $table->unsignedInteger('token_estimate')->default(0);
            $table->string('module_key')->nullable()->index();
            $table->string('route_key')->nullable()->index();
            $table->boolean('active')->default(true)->index();
            $table->timestamps();

            $table->unique(['knowledge_entry_id', 'chunk_index']);
            $table->index(['active', 'route_key', 'module_key'], 'ai_helper_chunks_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_helper_knowledge_chunks');

        Schema::table('ai_helper_knowledge_entries', function (Blueprint $table) {
            $table->dropIndex('ai_helper_knowledge_use_idx');
            $table->dropIndex('ai_helper_knowledge_review_idx');
            $table->dropColumn([
                'visibility',
                'review_status',
                'reviewed_at',
                'review_note',
                'processed_at',
                'content_hash',
            ]);
            $table->dropConstrainedForeignId('reviewed_by');
        });
    }
};
