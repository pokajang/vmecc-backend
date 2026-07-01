<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_helper_knowledge_entries', function (Blueprint $table) {
            $table->unsignedInteger('pdf_page_count')->nullable()->after('content_hash');
            $table->unsignedInteger('pdf_image_count')->nullable()->after('pdf_page_count');
            $table->unsignedInteger('pdf_pages_with_images')->nullable()->after('pdf_image_count');
            $table->unsignedInteger('pdf_readable_text_characters')->nullable()->after('pdf_pages_with_images');
            $table->unsignedInteger('pdf_readable_word_count')->nullable()->after('pdf_readable_text_characters');
            $table->unsignedTinyInteger('pdf_image_coverage_estimate')->nullable()->after('pdf_readable_word_count');
            $table->json('processing_warnings')->nullable()->after('pdf_image_coverage_estimate');
        });
    }

    public function down(): void
    {
        Schema::table('ai_helper_knowledge_entries', function (Blueprint $table) {
            $table->dropColumn([
                'pdf_page_count',
                'pdf_image_count',
                'pdf_pages_with_images',
                'pdf_readable_text_characters',
                'pdf_readable_word_count',
                'pdf_image_coverage_estimate',
                'processing_warnings',
            ]);
        });
    }
};
