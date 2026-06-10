<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('report_drafts', 'draft_id')) {
            Schema::table('report_drafts', function (Blueprint $table) {
                $table->string('draft_id', 80)->nullable()->after('user_id');
            });
        }
        if (!Schema::hasColumn('report_drafts', 'title')) {
            Schema::table('report_drafts', function (Blueprint $table) {
                $table->string('title', 190)->nullable()->after('report_type');
            });
        }
        if (!Schema::hasColumn('report_drafts', 'origin_mode')) {
            Schema::table('report_drafts', function (Blueprint $table) {
                $table->string('origin_mode', 16)->nullable()->after('title');
            });
        }
        if (!Schema::hasColumn('report_drafts', 'source_report_uid')) {
            Schema::table('report_drafts', function (Blueprint $table) {
                $table->string('source_report_uid', 190)->nullable()->after('origin_mode');
            });
        }
        if (!Schema::hasColumn('report_drafts', 'saved_at')) {
            Schema::table('report_drafts', function (Blueprint $table) {
                $table->timestamp('saved_at')->nullable()->after('payload');
            });
        }

        DB::table('report_drafts')
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('report_drafts')
                        ->where('id', $row->id)
                        ->update([
                            'draft_id' => $row->draft_id ?: ('drf_' . Str::lower(Str::random(20))),
                            'saved_at' => $row->saved_at ?: $row->updated_at ?: now(),
                        ]);
                }
            });

        DB::statement('ALTER TABLE report_drafts DROP CONSTRAINT IF EXISTS report_drafts_user_type_unique');
        DB::statement('DROP INDEX IF EXISTS report_drafts_user_type_unique');

        Schema::table('report_drafts', function (Blueprint $table) {
            $table->unique(['user_id', 'draft_id'], 'report_drafts_user_draft_unique');
            $table->index(['user_id', 'report_type', 'updated_at'], 'report_drafts_user_type_updated_idx');
        });
    }

    public function down(): void
    {
        Schema::table('report_drafts', function (Blueprint $table) {
            $table->dropUnique('report_drafts_user_draft_unique');
            $table->dropIndex('report_drafts_user_type_updated_idx');
            $table->unique(['user_id', 'report_type'], 'report_drafts_user_type_unique');
            $table->dropColumn(['draft_id', 'title', 'origin_mode', 'source_report_uid', 'saved_at']);
        });
    }
};
