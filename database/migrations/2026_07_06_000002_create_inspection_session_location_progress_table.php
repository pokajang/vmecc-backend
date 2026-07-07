<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspection_session_location_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inspection_session_id')->constrained('inspection_sessions')->cascadeOnDelete();
            $table->string('zone', 80)->default('');
            $table->string('main_location', 190)->default('');
            $table->string('sub_location', 190)->default('');
            $table->string('status', 32)->default('completed')->index();
            $table->unsignedInteger('expected_count')->default(0);
            $table->unsignedInteger('completed_count')->default(0);
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(
                ['inspection_session_id', 'zone', 'main_location', 'sub_location'],
                'inspection_session_location_progress_unique',
            );
            $table->index(
                ['inspection_session_id', 'status'],
                'inspection_session_location_progress_status_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_session_location_progress');
    }
};
