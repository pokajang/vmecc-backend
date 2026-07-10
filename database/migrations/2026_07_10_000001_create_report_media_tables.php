<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_media', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 80)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('module', 32);
            $table->string('disk', 32)->default('local');
            $table->string('storage_path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 80);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->timestamps();
            $table->index(['user_id', 'module']);
            $table->index('created_at');
        });

        Schema::create('report_media_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_media_id')->constrained('report_media')->cascadeOnDelete();
            $table->string('parent_type', 40);
            $table->string('parent_key', 190);
            $table->timestamps();
            $table->unique(['report_media_id', 'parent_type', 'parent_key'], 'report_media_parent_unique');
            $table->index(['parent_type', 'parent_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_media_links');
        Schema::dropIfExists('report_media');
    }
};
