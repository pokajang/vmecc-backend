<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deleted_teams', function (Blueprint $table): void {
            $table->unsignedBigInteger('original_team_id')->nullable()->after('id')->index();
            $table->string('group', 100)->nullable()->after('name');
            $table->json('dependencies_snapshot')->nullable()->after('members_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('deleted_teams', function (Blueprint $table): void {
            $table->dropIndex(['original_team_id']);
            $table->dropColumn(['original_team_id', 'group', 'dependencies_snapshot']);
        });
    }
};
