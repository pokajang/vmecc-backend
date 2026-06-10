<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop the hard CHECK constraint so rosters can store any shift slug
        // (custom shift names defined by the admin, plus the built-ins day/night/normal).
        DB::statement('ALTER TABLE rosters DROP CONSTRAINT IF EXISTS rosters_shift_check');

        // Add display-order column to custom_shifts for deterministic row ordering.
        Schema::table('custom_shifts', function (Blueprint $table) {
            $table->unsignedSmallInteger('sort_order')->default(0)->after('end');
        });
    }

    public function down(): void
    {
        Schema::table('custom_shifts', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });

        DB::statement("
            ALTER TABLE rosters
            ADD CONSTRAINT rosters_shift_check CHECK (shift IN ('day', 'night'))
        ");
    }
};
