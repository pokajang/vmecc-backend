<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_role_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->restrictOnDelete();
            $table->foreignId('from_team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->foreignId('to_team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->foreignId('from_assignment_id')
                ->nullable()
                ->constrained('user_role_assignments')
                ->nullOnDelete();
            $table->foreignId('to_assignment_id')
                ->nullable()
                ->constrained('user_role_assignments')
                ->nullOnDelete();
            $table->foreignId('handover_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('transferred_by_user_id')->constrained('users')->restrictOnDelete();
            $table->date('effective_date');
            $table->unsignedInteger('pending_handover_count')->default(0);
            $table->string('reason', 500);
            $table->timestamps();

            $table->index(['user_id', 'effective_date']);
            $table->index(['from_team_id', 'to_team_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_role_transfers');
    }
};
