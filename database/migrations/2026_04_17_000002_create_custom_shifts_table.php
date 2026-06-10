<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_shifts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('start', 5); // HH:MM
            $table->string('end', 5);   // HH:MM
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_shifts');
    }
};
