<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_assignment_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_assignment_id')->constrained('salary_assignments')->cascadeOnDelete();
            $table->string('event_type')->default('updated');
            $table->json('before_data')->nullable();
            $table->json('after_data')->nullable();
            $table->string('actor_name')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->index(['salary_assignment_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_assignment_histories');
    }
};
