<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('overtime_records', function (Blueprint $table) {
            $table->index(
                ['status', 'overtime_type', 'applied_at'],
                'overtime_records_status_type_applied_idx',
            );
            $table->index(
                ['applied_at', 'duration_minutes'],
                'overtime_records_applied_duration_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('overtime_records', function (Blueprint $table) {
            $table->dropIndex('overtime_records_status_type_applied_idx');
            $table->dropIndex('overtime_records_applied_duration_idx');
        });
    }
};

