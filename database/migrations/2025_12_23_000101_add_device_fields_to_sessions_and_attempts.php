<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('user_sessions', function (Blueprint $table) {
            $table->string('device_id')->nullable()->after('user_agent');
        });

        Schema::table('login_attempts', function (Blueprint $table) {
            $table->string('device_id')->nullable()->after('user_agent');
            $table->string('device_info')->nullable()->after('device_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('login_attempts', function (Blueprint $table) {
            $table->dropColumn(['device_id', 'device_info']);
        });

        Schema::table('user_sessions', function (Blueprint $table) {
            $table->dropColumn(['device_id']);
        });
    }
};
