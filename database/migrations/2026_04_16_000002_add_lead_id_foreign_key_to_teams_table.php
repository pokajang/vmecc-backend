<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add a foreign-key constraint on teams.lead_id → users.id.
 *
 * Uses nullOnDelete so that deleting a user who is listed as a team lead
 * clears the reference rather than blocking the delete or orphaning the value.
 *
 * Runs DB::statement to NULL out any existing stale lead_id values first so
 * the constraint can be applied without violating referential integrity on
 * legacy data.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Clear any dangling lead_id values that reference deleted or non-existent users
        \Illuminate\Support\Facades\DB::statement("
            UPDATE teams
            SET lead_id = NULL
            WHERE lead_id IS NOT NULL
              AND NOT EXISTS (
                  SELECT 1 FROM users WHERE users.id = teams.lead_id
              )
        ");

        Schema::table('teams', function (Blueprint $table) {
            $table->foreign('lead_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropForeign(['lead_id']);
        });
    }
};
