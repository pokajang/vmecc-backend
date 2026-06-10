<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('overtime_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('display_id');
            $table->string('overtime_type')->default('weekday');
            $table->date('claim_date')->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->boolean('is_overnight')->default(false);
            $table->unsignedInteger('duration_minutes')->default(0);
            $table->text('reason')->nullable();
            $table->string('status')->default('Pending');
            $table->timestamp('applied_at')->nullable();
            $table->string('workflow_stage')->nullable();
            $table->json('workflow_snapshot')->nullable();
            $table->string('next_action_role')->nullable();
            $table->json('applicant_roles')->nullable();
            $table->json('approval_history')->nullable();
            $table->string('submitted_by')->nullable();
            $table->foreignId('attachment_id')->nullable()->constrained('workflow_attachments')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['user_id', 'display_id']);
            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'claim_date']);
            $table->index('workflow_stage');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overtime_records');
    }
};
