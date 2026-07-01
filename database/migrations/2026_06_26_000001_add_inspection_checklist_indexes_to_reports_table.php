<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->json('inspection_checklist_item_ids')->nullable()->after('payload');
            $table->json('inspection_checklist_item_labels')->nullable()->after('inspection_checklist_item_ids');
            $table->boolean('inspection_has_checklist')->default(false)->after('inspection_checklist_item_labels');
            $table->index(['report_type', 'inspection_has_checklist'], 'reports_type_inspection_checklist_idx');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropIndex('reports_type_inspection_checklist_idx');
            $table->dropColumn([
                'inspection_checklist_item_ids',
                'inspection_checklist_item_labels',
                'inspection_has_checklist',
            ]);
        });
    }
};
