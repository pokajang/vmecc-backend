<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_helper_knowledge_entries', function (Blueprint $table) {
            $table->string('quality_status', 32)->nullable()->after('extraction_complete')->index();
            $table->unsignedInteger('pages_indexed')->default(0)->after('quality_status');
            $table->unsignedInteger('pages_native')->default(0)->after('pages_indexed');
            $table->unsignedInteger('pages_ocr')->default(0)->after('pages_native');
            $table->unsignedInteger('pages_blank')->default(0)->after('pages_ocr');
            $table->unsignedInteger('pages_visual_only')->default(0)->after('pages_blank');
            $table->unsignedInteger('pages_unreadable')->default(0)->after('pages_visual_only');
            $table->json('processing_findings')->nullable()->after('processing_warnings');
        });

        Schema::create('ai_helper_knowledge_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_entry_id')
                ->constrained('ai_helper_knowledge_entries')
                ->cascadeOnDelete();
            $table->unsignedInteger('ingestion_version')->default(1);
            $table->unsignedInteger('page_number');
            $table->string('outcome', 32);
            $table->unsignedInteger('native_character_count')->default(0);
            $table->unsignedInteger('native_word_count')->default(0);
            $table->unsignedInteger('ocr_character_count')->default(0);
            $table->unsignedInteger('ocr_word_count')->default(0);
            $table->unsignedInteger('image_count')->default(0);
            $table->boolean('ocr_attempted')->default(false);
            $table->boolean('ocr_succeeded')->default(false);
            $table->json('findings')->nullable();
            $table->timestamps();

            $table->unique(
                ['knowledge_entry_id', 'ingestion_version', 'page_number'],
                'ai_helper_pages_entry_version_page_unique'
            );
            $table->index(['knowledge_entry_id', 'outcome'], 'ai_helper_pages_entry_outcome_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_helper_knowledge_pages');

        Schema::table('ai_helper_knowledge_entries', function (Blueprint $table) {
            $table->dropIndex(['quality_status']);
            $table->dropColumn([
                'quality_status',
                'pages_indexed',
                'pages_native',
                'pages_ocr',
                'pages_blank',
                'pages_visual_only',
                'pages_unreadable',
                'processing_findings',
            ]);
        });
    }
};
