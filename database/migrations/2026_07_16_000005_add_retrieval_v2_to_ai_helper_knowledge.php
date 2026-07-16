<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_helper_knowledge_entries', function (Blueprint $table) {
            $table->json('retrieval_metadata')->nullable()->after('tags');
            $table->json('embedding')->nullable()->after('retrieval_metadata');
            $table->string('embedding_model', 80)->nullable()->after('embedding');
            $table->string('embedding_hash', 64)->nullable()->after('embedding_model');
            $table->string('embedding_status', 24)->default('pending')->after('embedding_hash')->index();
            $table->timestamp('embedded_at')->nullable()->after('embedding_status');
            $table->text('embedding_error')->nullable()->after('embedded_at');
        });

        Schema::table('ai_helper_knowledge_chunks', function (Blueprint $table) {
            $table->json('heading_path')->nullable()->after('content');
            $table->string('content_type', 24)->default('text')->after('heading_path')->index();
            $table->mediumText('search_text')->nullable()->after('content_type');
            $table->json('embedding')->nullable()->after('search_text');
            $table->string('embedding_model', 80)->nullable()->after('embedding');
            $table->string('embedding_hash', 64)->nullable()->after('embedding_model');
            $table->timestamp('embedded_at')->nullable()->after('embedding_hash');
        });

        Schema::table('ai_helper_messages', function (Blueprint $table) {
            $table->json('retrieval_metadata')->nullable()->after('sources');
        });
    }

    public function down(): void
    {
        Schema::table('ai_helper_messages', function (Blueprint $table) {
            $table->dropColumn('retrieval_metadata');
        });

        Schema::table('ai_helper_knowledge_chunks', function (Blueprint $table) {
            $table->dropIndex(['content_type']);
            $table->dropColumn([
                'heading_path',
                'content_type',
                'search_text',
                'embedding',
                'embedding_model',
                'embedding_hash',
                'embedded_at',
            ]);
        });

        Schema::table('ai_helper_knowledge_entries', function (Blueprint $table) {
            $table->dropIndex(['embedding_status']);
            $table->dropColumn([
                'retrieval_metadata',
                'embedding',
                'embedding_model',
                'embedding_hash',
                'embedding_status',
                'embedded_at',
                'embedding_error',
            ]);
        });
    }
};
