<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('module', 40);
            $table->string('event_type', 80);
            $table->string('record_type', 120);
            $table->unsignedBigInteger('record_id')->nullable();
            $table->string('record_display_id')->nullable();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->json('actor_data')->nullable();
            $table->json('recipient_user_ids')->nullable();
            $table->boolean('action_required')->default(false);
            $table->timestamp('resolved_at')->nullable();
            $table->string('title');
            $table->text('message');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['module', 'event_type']);
            $table->index(['record_type', 'record_id']);
            $table->index(['owner_user_id', 'created_at']);
            $table->index('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_notifications');
    }
};
