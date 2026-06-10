<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leaves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('display_id');          // LV-AL-2026-001, unique per user
            $table->string('leave_type');          // Annual Leave, Medical Leave, etc.
            $table->string('status')->default('Draft'); // Draft, Pending, Approved, Rejected, Cancelled
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->decimal('days', 5, 1)->default(0);
            $table->string('work_shift')->nullable();        // normal, day12, night12
            $table->string('start_time_slot')->nullable();  // shift-start, midpoint
            $table->string('end_time_slot')->nullable();    // midpoint, shift-end
            $table->text('reason')->nullable();
            $table->string('cover_by')->nullable();
            $table->timestamp('applied_at')->nullable();    // set when submitted (status → Pending)
            $table->string('workflow_stage')->nullable();   // review, recommend, approve, done
            $table->json('workflow_snapshot')->nullable();  // {reviewRole, recommendRole, approveRole, requireRecommendation}
            $table->string('next_action_role')->nullable();
            $table->json('applicant_roles')->nullable();    // roles at time of submission
            $table->json('approval_history')->nullable();   // [{id, at, action, by, byUserId, remarks}]
            $table->string('submitted_by')->nullable();     // actor name at submission
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['user_id', 'display_id']);
            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'applied_at']);
            $table->index('workflow_stage');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leaves');
    }
};
