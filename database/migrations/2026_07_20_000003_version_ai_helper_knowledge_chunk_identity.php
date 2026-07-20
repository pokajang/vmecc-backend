<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY_UNIQUE = 'ai_helper_knowledge_chunks_knowledge_entry_id_chunk_index_unique';

    private const VERSIONED_UNIQUE = 'ai_helper_chunks_entry_version_index_unique';

    public function up(): void
    {
        Schema::table('ai_helper_knowledge_chunks', function (Blueprint $table) {
            $table->dropUnique(self::LEGACY_UNIQUE);
            $table->unique(
                ['knowledge_entry_id', 'ingestion_version', 'chunk_index'],
                self::VERSIONED_UNIQUE,
            );
        });
    }

    public function down(): void
    {
        // A rollback cannot represent more than one version under the legacy
        // key. Retain the active version (or the newest staged version when no
        // version is active) before restoring the old constraint.
        DB::table('ai_helper_knowledge_chunks')
            ->select('knowledge_entry_id')
            ->distinct()
            ->orderBy('knowledge_entry_id')
            ->each(function (object $row): void {
                $chunks = DB::table('ai_helper_knowledge_chunks')
                    ->where('knowledge_entry_id', $row->knowledge_entry_id);
                $keepVersion = (clone $chunks)
                    ->where('active', true)
                    ->max('ingestion_version')
                    ?? (clone $chunks)->max('ingestion_version');

                if ($keepVersion !== null) {
                    (clone $chunks)->where('ingestion_version', '!=', $keepVersion)->delete();
                }
            });

        Schema::table('ai_helper_knowledge_chunks', function (Blueprint $table) {
            $table->dropUnique(self::VERSIONED_UNIQUE);
            $table->unique(['knowledge_entry_id', 'chunk_index'], self::LEGACY_UNIQUE);
        });
    }
};
