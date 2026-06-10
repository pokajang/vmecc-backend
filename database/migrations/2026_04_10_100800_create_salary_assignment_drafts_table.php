<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_assignment_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('draft_name')->nullable();
            $table->foreignId('source_assignment_id')->nullable()->constrained('salary_assignments')->nullOnDelete();
            $table->json('payload');
            $table->timestamp('saved_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'saved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_assignment_drafts');
    }
};
