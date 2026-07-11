<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leaves', function (Blueprint $table): void {
            $table->json('roster_impact_snapshot')->nullable()->after('approval_history');
        });
    }

    public function down(): void
    {
        Schema::table('leaves', function (Blueprint $table): void {
            $table->dropColumn('roster_impact_snapshot');
        });
    }
};
