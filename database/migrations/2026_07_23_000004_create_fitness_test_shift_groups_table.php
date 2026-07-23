<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fitness_test_shift_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fitness_test_report_id')->constrained('fitness_test_reports')->cascadeOnDelete();
            $table->string('source_group_uid', 190)->nullable();
            $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->string('shift_name_snapshot', 190)->nullable();
            $table->foreignId('assessor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('assessor_name_snapshot', 190)->nullable();
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();

            $table->index('team_id', 'fitness_shift_groups_team_id_idx');
            $table->index('assessor_user_id', 'fitness_shift_groups_assessor_user_id_idx');
            $table->index(['fitness_test_report_id', 'display_order'], 'fitness_shift_groups_report_display_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fitness_test_shift_groups');
    }
};
