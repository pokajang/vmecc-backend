<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Enforce that shift is only ever 'day' or 'night' at the database level.
        // PostgreSQL CHECK constraint — no equivalent Schema builder method.
        DB::statement("
            ALTER TABLE rosters
            ADD CONSTRAINT rosters_shift_check CHECK (shift IN ('day', 'night'))
        ");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE rosters DROP CONSTRAINT IF EXISTS rosters_shift_check');
    }
};
