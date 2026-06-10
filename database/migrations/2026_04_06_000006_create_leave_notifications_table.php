<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('event_type');               // submitted, reviewed, recommended, approved, rejected, cancelled
            $table->foreignId('leave_id')->nullable()->constrained('leaves')->nullOnDelete();
            $table->string('leave_display_id');
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->json('actor_data');                 // {userId, name, email}
            $table->json('recipient_user_ids');         // resolved user ids to notify
            $table->boolean('action_required')->default(false);
            $table->timestamp('resolved_at')->nullable();
            $table->string('title');
            $table->text('message');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['owner_user_id', 'event_type']);
            $table->index('leave_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_notifications');
    }
};
