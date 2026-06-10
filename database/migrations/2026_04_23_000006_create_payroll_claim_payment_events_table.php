<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_claim_payment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('claim_id')->constrained('payroll_claims')->cascadeOnDelete();
            $table->enum('action', ['mark_paid', 'unmark_paid']);
            $table->date('payment_date')->nullable();
            $table->string('payment_reference')->nullable();
            $table->text('note')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('acted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['claim_id', 'action']);
            $table->index('payment_date');
            $table->index('acted_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_claim_payment_events');
    }
};
