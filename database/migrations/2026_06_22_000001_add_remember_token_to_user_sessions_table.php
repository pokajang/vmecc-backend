<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('user_sessions', 'remember_token_hash')) {
                $table->string('remember_token_hash', 128)->nullable()->after('csrf_token_hash');
            }

            if (! Schema::hasColumn('user_sessions', 'remember_expires_at')) {
                $table->timestamp('remember_expires_at')->nullable()->after('remember_token_hash');
            }
        });
    }

    public function down(): void
    {
        Schema::table('user_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('user_sessions', 'remember_expires_at')) {
                $table->dropColumn('remember_expires_at');
            }

            if (Schema::hasColumn('user_sessions', 'remember_token_hash')) {
                $table->dropColumn('remember_token_hash');
            }
        });
    }
};
