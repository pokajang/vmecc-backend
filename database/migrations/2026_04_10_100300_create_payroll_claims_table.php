<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('display_id');
            $table->enum('claim_type', ['expense', 'salary', 'exceptional'])->default('expense');
            $table->string('category')->nullable();
            $table->string('period')->nullable();
            $table->string('period_value')->nullable();
            $table->decimal('amount', 14, 2)->default(0);
            $table->decimal('approved_overtime_payout', 14, 2)->default(0);
            $table->string('status')->default('Pending');
            $table->timestamp('submitted_at')->nullable();
            $table->string('submitted_by')->nullable();
            $table->string('submitted_by_name')->nullable();
            $table->string('updated_by')->nullable();
            $table->string('updated_by_name')->nullable();
            $table->string('workflow_stage')->nullable();
            $table->json('workflow_snapshot')->nullable();
            $table->string('next_action_role')->nullable();
            $table->json('approval_history')->nullable();
            $table->json('payroll_snapshot')->nullable();
            $table->json('overtime_rows')->nullable();
            $table->json('overtime_rate_snapshot')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('attachment_id')->nullable()->constrained('workflow_attachments')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['user_id', 'display_id']);
            $table->index(['user_id', 'claim_type', 'status']);
            $table->index('period_value');
            $table->index('workflow_stage');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_claims');
    }
};
