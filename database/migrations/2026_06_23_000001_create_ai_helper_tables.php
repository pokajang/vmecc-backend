<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_helper_knowledge_entries', function (Blueprint $table) {
            $table->id();
            $table->string('module_key')->nullable()->index();
            $table->string('route_key')->nullable()->index();
            $table->string('title');
            $table->text('content');
            $table->json('tags')->nullable();
            $table->boolean('active')->default(true)->index();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['active', 'module_key', 'route_key']);
        });

        Schema::create('ai_helper_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->json('latest_route_context')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('ai_helper_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained('ai_helper_threads')->cascadeOnDelete();
            $table->string('role', 16);
            $table->text('content')->nullable();
            $table->json('route_context')->nullable();
            $table->string('openai_response_id')->nullable();
            $table->string('status', 24)->default('completed');
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['thread_id', 'created_at']);
            $table->index(['role', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_helper_messages');
        Schema::dropIfExists('ai_helper_threads');
        Schema::dropIfExists('ai_helper_knowledge_entries');
    }
};
