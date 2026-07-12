<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspection_duty_confirmations', function (Blueprint $table) {
            $table->id();
            $table->uuid('token_id')->unique();
            $table->char('token_hash', 64)->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('operation', 32);
            $table->string('context_version', 80);
            $table->string('source_version', 80)->nullable();
            $table->string('context_hash', 64);
            $table->json('context_snapshot');
            $table->string('form_id', 100)->nullable();
            $table->string('record_id', 190)->nullable();
            $table->string('idempotency_key', 190)->nullable();
            $table->string('request_id', 100)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('reason', 500)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'operation', 'expires_at'], 'inspection_duty_confirmation_lookup_idx');
            $table->index(['expires_at', 'consumed_at', 'revoked_at'], 'inspection_duty_confirmation_cleanup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_duty_confirmations');
    }
};
