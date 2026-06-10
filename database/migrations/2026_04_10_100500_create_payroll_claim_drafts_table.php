<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_claim_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('claim_type', ['expense', 'salary', 'exceptional']);
            $table->string('draft_id')->nullable();
            $table->json('payload');
            $table->timestamp('saved_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'claim_type']);
            $table->unique(['user_id', 'claim_type', 'draft_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_claim_drafts');
    }
};
