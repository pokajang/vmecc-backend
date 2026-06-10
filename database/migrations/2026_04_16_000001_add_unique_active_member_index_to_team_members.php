<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prevent duplicate active memberships for the same (team_id, user_id).
 *
 * PostgreSQL supports partial (filtered) unique indexes natively, so we use
 * a WHERE clause to scope the constraint to rows where ended_at IS NULL and
 * user_id IS NOT NULL. Closed historical rows are never affected.
 *
 * This prevents two simultaneous active rows for the same user + team at the
 * database level, complementing the application-layer dual-team guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            CREATE UNIQUE INDEX uq_team_members_active_user
            ON team_members (user_id, team_id)
            WHERE ended_at IS NULL
              AND user_id IS NOT NULL
        ");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS uq_team_members_active_user');
    }
};
