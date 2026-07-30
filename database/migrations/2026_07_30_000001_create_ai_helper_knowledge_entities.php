<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_helper_knowledge_entities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_entry_id')
                ->constrained('ai_helper_knowledge_entries')
                ->cascadeOnDelete();
            $table->foreignId('source_chunk_id')
                ->nullable()
                ->constrained('ai_helper_knowledge_chunks')
                ->cascadeOnDelete();
            $table->string('canonical_name', 255);
            $table->string('normalized_name', 255);
            $table->string('entity_type', 40);
            $table->decimal('confidence', 4, 3)->default(1);
            $table->unsignedInteger('ingestion_version')->default(1);
            $table->boolean('active')->default(false);
            $table->timestamps();

            $table->unique(
                ['knowledge_entry_id', 'ingestion_version', 'entity_type', 'normalized_name'],
                'ai_entity_entry_version_type_name_unique',
            );
            $table->index(
                ['knowledge_entry_id', 'active', 'ingestion_version'],
                'ai_entity_entry_active_version_idx',
            );
            $table->index(['entity_type', 'normalized_name'], 'ai_entity_type_name_idx');
        });

        Schema::create('ai_helper_knowledge_entity_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')
                ->constrained('ai_helper_knowledge_entities')
                ->cascadeOnDelete();
            $table->string('alias', 255);
            $table->string('normalized_alias', 255);
            $table->string('alias_type', 32)->default('extracted');
            $table->string('language', 8)->nullable();
            $table->timestamps();

            $table->unique(['entity_id', 'normalized_alias'], 'ai_entity_alias_unique');
            $table->index('normalized_alias', 'ai_entity_alias_normalized_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_helper_knowledge_entity_aliases');
        Schema::dropIfExists('ai_helper_knowledge_entities');
    }
};
