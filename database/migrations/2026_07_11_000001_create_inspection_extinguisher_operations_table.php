<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspection_extinguisher_operations', function (Blueprint $table): void {
            $table->id();
            $table->string('operation_uid', 190)->unique();
            $table->foreignId('inspection_session_id')->constrained('inspection_sessions')->cascadeOnDelete();
            $table->string('canonical_asset_key', 190);
            $table->string('operation_type', 20);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('base_version')->default(0);
            $table->unsignedInteger('result_version')->nullable();
            $table->char('payload_hash', 64);
            $table->string('status', 24)->default('pending');
            $table->string('outcome_code', 80)->nullable();
            $table->json('response_payload')->nullable();
            $table->timestamps();

            $table->index(
                ['inspection_session_id', 'canonical_asset_key', 'created_at'],
                'inspection_fe_operations_asset_time_idx',
            );
            $table->index(
                ['inspection_session_id', 'status'],
                'inspection_fe_operations_status_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_extinguisher_operations');
    }
};
