<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table): void {
            $table->unsignedSmallInteger('domain_projection_version')->nullable()->after('revision')
                ->index('reports_domain_projection_version_idx');
            $table->timestamp('domain_projected_at')->nullable()->after('domain_projection_version');
            $table->string('domain_projection_status', 24)->nullable()->after('domain_projected_at')
                ->index('reports_domain_projection_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table): void {
            $table->dropIndex('reports_domain_projection_status_idx');
            $table->dropIndex('reports_domain_projection_version_idx');
            $table->dropColumn([
                'domain_projection_version',
                'domain_projected_at',
                'domain_projection_status',
            ]);
        });
    }
};
