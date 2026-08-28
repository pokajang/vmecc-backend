<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('er_assessment_types', function (Blueprint $table) {
            $table->id();
            $table->string('type_key', 140)->unique();
            $table->string('label', 140);
            $table->string('worst_case_scenario', 500)->nullable();
            $table->json('requirements');
            $table->string('icon_key', 80)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('er_assessment_types');
    }
};
