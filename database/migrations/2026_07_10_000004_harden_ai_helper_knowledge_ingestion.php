<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_helper_knowledge_entries', function (Blueprint $table) {
            $table->mediumText('content')->change();
            $table->uuid('ingestion_run_id')->nullable()->after('version')->index('ai_helper_knowledge_ingestion_run_idx');
            $table->unsignedInteger('ingestion_version')->default(1)->after('ingestion_run_id');
            $table->timestamp('ingestion_started_at')->nullable()->after('ingestion_version');
            $table->timestamp('ingestion_completed_at')->nullable()->after('ingestion_started_at');
            $table->string('extraction_mode', 32)->nullable()->after('ingestion_completed_at');
            $table->boolean('extraction_complete')->default(false)->after('extraction_mode')->index();
            $table->unsignedBigInteger('extracted_characters')->default(0)->after('extraction_complete');
        });

        Schema::table('ai_helper_knowledge_chunks', function (Blueprint $table) {
            $table->unsignedInteger('page_start')->nullable()->after('chunk_index');
            $table->unsignedInteger('page_end')->nullable()->after('page_start');
            $table->string('extraction_mode', 32)->nullable()->after('token_estimate');
            $table->unsignedInteger('ingestion_version')->default(1)->after('extraction_mode');
            $table->index(['knowledge_entry_id', 'ingestion_version'], 'ai_helper_chunks_ingestion_idx');
        });
    }

    public function down(): void
    {
        Schema::table('ai_helper_knowledge_chunks', function (Blueprint $table) {
            $table->dropIndex('ai_helper_chunks_ingestion_idx');
            $table->dropColumn(['page_start', 'page_end', 'extraction_mode', 'ingestion_version']);
        });

        Schema::table('ai_helper_knowledge_entries', function (Blueprint $table) {
            $table->text('content')->change();
            $table->dropIndex('ai_helper_knowledge_ingestion_run_idx');
            $table->dropColumn([
                'ingestion_run_id',
                'ingestion_version',
                'ingestion_started_at',
                'ingestion_completed_at',
                'extraction_mode',
                'extraction_complete',
                'extracted_characters',
            ]);
        });
    }
};
