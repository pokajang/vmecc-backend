<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('user_sessions', 'client_mode')) {
                $table->string('client_mode', 20)->nullable()->after('device_id');
            }
        });

        Schema::table('login_attempts', function (Blueprint $table) {
            if (! Schema::hasColumn('login_attempts', 'client_mode')) {
                $table->string('client_mode', 20)->nullable()->after('device_info');
            }
        });
    }

    public function down(): void
    {
        Schema::table('login_attempts', function (Blueprint $table) {
            if (Schema::hasColumn('login_attempts', 'client_mode')) {
                $table->dropColumn('client_mode');
            }
        });

        Schema::table('user_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('user_sessions', 'client_mode')) {
                $table->dropColumn('client_mode');
            }
        });
    }
};
