<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->string('duty_context_status', 32)->nullable()->after('scope_team_id');
            $table->string('duty_context_version', 80)->nullable()->after('duty_context_status');
            $table->string('duty_source_version', 80)->nullable()->after('duty_context_version');
            $table->json('duty_context_snapshot')->nullable()->after('duty_source_version');
        });

        Schema::table('inspection_sessions', function (Blueprint $table) {
            $table->string('duty_context_status', 32)->nullable()->after('scope_key');
            $table->string('duty_context_version', 80)->nullable()->after('duty_context_status');
            $table->string('duty_source_version', 80)->nullable()->after('duty_context_version');
            $table->json('duty_context_snapshot')->nullable()->after('duty_source_version');
        });
    }

    public function down(): void
    {
        Schema::table('inspection_sessions', function (Blueprint $table) {
            $table->dropColumn(['duty_context_status', 'duty_context_version', 'duty_source_version', 'duty_context_snapshot']);
        });
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn(['duty_context_status', 'duty_context_version', 'duty_source_version', 'duty_context_snapshot']);
        });
    }
};
