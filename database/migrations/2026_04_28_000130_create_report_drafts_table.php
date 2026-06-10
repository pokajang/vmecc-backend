<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('report_type', 64);
            $table->json('payload');
            $table->timestamps();

            $table->unique(['user_id', 'report_type'], 'report_drafts_user_type_unique');
            $table->index(['report_type', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_drafts');
    }
};

