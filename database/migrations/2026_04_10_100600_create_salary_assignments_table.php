<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status')->default('Active');
            $table->date('effective_from')->nullable();
            $table->decimal('basic_salary', 14, 2)->default(0);
            $table->decimal('allowance_total', 14, 2)->default(0);
            $table->json('allowances')->nullable();
            $table->json('employee_contributions')->nullable();
            $table->json('employer_contributions')->nullable();
            $table->json('notes_history')->nullable();
            $table->string('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_user_id', 'status']);
            $table->index('effective_from');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_assignments');
    }
};
