<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_email_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id')->constrained('workflow_notifications')->cascadeOnDelete();
            $table->string('recipient_email');
            $table->string('status')->default('queued');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['notification_id', 'recipient_email']);
            $table->index(['status', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_email_deliveries');
    }
};
