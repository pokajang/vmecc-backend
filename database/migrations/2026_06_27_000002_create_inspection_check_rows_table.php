<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspection_check_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained('reports')->cascadeOnDelete();
            $table->string('report_uid', 190)->index('inspection_check_rows_report_uid_idx');
            $table->string('display_id', 190)->index('inspection_check_rows_display_id_idx');
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('inspection_type', 190)->default('');
            $table->string('inspection_type_key', 190)->default('');
            $table->string('location', 500)->default('');
            $table->string('main_location', 190)->default('');
            $table->string('sub_location', 190)->default('');
            $table->string('equipment', 190)->default('');
            $table->string('equipment_key', 190)->default('');
            $table->string('check_group', 120)->default('');
            $table->string('check_key', 120)->default('');
            $table->string('check_name', 190)->default('');
            $table->string('check_value', 120)->default('');
            $table->text('remarks')->nullable();
            $table->boolean('has_defect')->default(false);
            $table->string('report_status', 32)->default('');
            $table->unsignedInteger('report_version')->default(1);
            $table->unsignedInteger('report_revision')->default(1);
            $table->timestamp('submitted_at')->nullable();
            $table->string('source_payload_key', 120)->default('');
            $table->string('source_row_id', 190)->default('');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['owner_user_id', 'inspection_type'], 'inspection_check_rows_owner_type_idx');
            $table->index(['submitted_by_user_id', 'submitted_at'], 'inspection_check_rows_submitter_time_idx');
            $table->index(['inspection_type', 'check_key', 'check_value'], 'inspection_check_rows_type_check_value_idx');
            $table->index(['main_location', 'sub_location'], 'inspection_check_rows_location_idx');
            $table->index(['has_defect', 'submitted_at'], 'inspection_check_rows_defect_time_idx');
            $table->index(['equipment_key', 'check_key'], 'inspection_check_rows_equipment_check_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_check_rows');
    }
};
