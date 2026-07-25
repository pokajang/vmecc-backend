<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        'reports.fitness.manage',
        'reports.fitness.analytics.view',
        'reports.fitness.individual-results.view',
        'reports.fitness.export',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }
        foreach (self::PERMISSIONS as $permission) {
            DB::table('permissions')->updateOrInsert(['name' => $permission, 'guard_name' => 'web'], ['updated_at' => now(), 'created_at' => now()]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('permissions')) {
            DB::table('permissions')->whereIn('name', self::PERMISSIONS)->delete();
        }
    }
};
