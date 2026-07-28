<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_notification_outbox', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id')
                ->unique()
                ->constrained('workflow_notifications')
                ->cascadeOnDelete();
            $table->unsignedInteger('event_version')->default(1);
            $table->string('status', 24)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('processing_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['status', 'available_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_notification_outbox');
    }
};
