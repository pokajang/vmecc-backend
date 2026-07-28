<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('duty_coverage_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('acting_team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('home_team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->foreignId('acting_role_id')->constrained('roles')->restrictOnDelete();
            $table->foreignId('replaces_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('roster_id')->nullable()->constrained('rosters')->nullOnDelete();
            $table->string('shift_key', 80)->nullable();
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_until');
            $table->string('reason', 500)->nullable();
            $table->foreignId('approved_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason', 500)->nullable();
            $table->timestamps();

            $table->index(
                ['user_id', 'effective_from', 'effective_until'],
                'duty_coverage_user_window_idx',
            );
            $table->index(
                ['acting_team_id', 'acting_role_id', 'effective_from', 'effective_until'],
                'duty_coverage_team_role_window_idx',
            );
            $table->index(['cancelled_at', 'effective_until'], 'duty_coverage_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('duty_coverage_assignments');
    }
};
