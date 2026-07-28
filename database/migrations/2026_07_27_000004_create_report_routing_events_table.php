<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_routing_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained('reports')->cascadeOnDelete();
            $table->foreignId('team_role_transfer_id')
                ->nullable()
                ->constrained('team_role_transfers')
                ->nullOnDelete();
            $table->string('event_type', 80);
            $table->foreignId('from_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->string('required_role', 120)->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['report_id', 'created_at']);
            $table->index(['to_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_routing_events');
    }
};
