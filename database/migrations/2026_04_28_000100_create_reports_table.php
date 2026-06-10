<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_uid')->unique();
            $table->string('display_id')->index();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('report_type', 64)->index();
            $table->string('status', 32)->index();
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('revision')->default(1);
            $table->json('payload');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['owner_user_id', 'report_type']);
            $table->index(['owner_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};

