<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspection_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('session_uid', 190)->unique();
            $table->string('inspection_type', 190)->index();
            $table->string('inspection_type_key', 190)->index();
            $table->string('status', 32)->default('active')->index();
            $table->string('scope_zone', 80)->default('');
            $table->string('scope_main_location', 190)->default('');
            $table->json('scope')->nullable();
            $table->foreignId('started_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('submitted_report_uid', 190)->nullable()->index();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(
                ['inspection_type_key', 'status', 'scope_zone', 'scope_main_location'],
                'inspection_sessions_active_scope_idx',
            );
        });

        Schema::create('inspection_extinguisher_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inspection_session_id')->constrained('inspection_sessions')->cascadeOnDelete();
            $table->string('canonical_asset_key', 190);
            $table->foreignId('fire_extinguisher_id')->nullable()->constrained('inspection_fire_extinguishers')->nullOnDelete();
            $table->string('zone', 80)->default('');
            $table->string('main_location', 190)->default('');
            $table->string('sub_location', 190)->default('');
            $table->string('id_loc_no', 190)->default('');
            $table->string('barcode_no', 190)->default('');
            $table->string('status', 32)->default('completed')->index();
            $table->json('check_payload');
            $table->string('client_result_id', 190)->nullable();
            $table->foreignId('checked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_at')->nullable();
            $table->foreignId('lock_owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('lock_expires_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(
                ['inspection_session_id', 'canonical_asset_key'],
                'inspection_extinguisher_results_session_asset_unique',
            );
            $table->index(
                ['inspection_session_id', 'zone', 'main_location', 'sub_location'],
                'inspection_extinguisher_results_location_idx',
            );
            $table->index(
                ['inspection_session_id', 'status'],
                'inspection_extinguisher_results_session_status_idx',
            );
            $table->index(
                ['inspection_session_id', 'client_result_id'],
                'inspection_extinguisher_results_client_result_idx',
            );
        });

        Schema::create('inspection_session_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inspection_session_id')->constrained('inspection_sessions')->cascadeOnDelete();
            $table->foreignId('inspection_extinguisher_result_id')->nullable()->constrained('inspection_extinguisher_results')->nullOnDelete();
            $table->string('event_type', 80)->index();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['inspection_session_id', 'created_at'], 'inspection_session_events_session_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_session_events');
        Schema::dropIfExists('inspection_extinguisher_results');
        Schema::dropIfExists('inspection_sessions');
    }
};
