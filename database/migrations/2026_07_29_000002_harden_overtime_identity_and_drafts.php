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
        Schema::table('overtime_records', function (Blueprint $table) {
            $table->string('public_id', 26)->nullable()->after('id');
            $table->string('submission_key', 64)->nullable()->after('display_id');
            $table->unique('public_id', 'overtime_records_public_id_unique');
            $table->unique(
                ['user_id', 'submission_key'],
                'overtime_records_user_submission_unique',
            );
        });

        DB::table('overtime_records')
            ->whereNull('public_id')
            ->orderBy('id')
            ->each(fn (object $row) => DB::table('overtime_records')
                ->where('id', $row->id)
                ->update(['public_id' => (string) Str::ulid()]));

        Schema::table('overtime_records', function (Blueprint $table) {
            $table->string('public_id', 26)->nullable(false)->change();
        });

        Schema::table('overtime_drafts', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1)->after('saved_at');
        });
    }

    public function down(): void
    {
        Schema::table('overtime_drafts', function (Blueprint $table) {
            $table->dropColumn('version');
        });

        Schema::table('overtime_records', function (Blueprint $table) {
            $table->dropUnique('overtime_records_user_submission_unique');
            $table->dropUnique('overtime_records_public_id_unique');
            $table->dropColumn(['public_id', 'submission_key']);
        });
    }
};
