<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_drill_environment_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->string('value', 140);
            $table->string('title', 140);
            $table->string('description', 500)->nullable();
            $table->string('icon_key', 80)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'value']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_drill_environment_options');
    }
};
