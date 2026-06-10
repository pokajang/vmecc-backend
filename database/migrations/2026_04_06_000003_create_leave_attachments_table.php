<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_id')->nullable()->constrained('leaves')->nullOnDelete();
            $table->string('original_name');
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->unsignedBigInteger('original_size')->nullable();
            $table->boolean('was_compressed')->default(false);
            $table->string('storage_path');
            $table->timestamps();

            $table->index(['user_id', 'leave_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_attachments');
    }
};
