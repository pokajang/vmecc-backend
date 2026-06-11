<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('user_sessions', 'csrf_token_hash')) {
                $table->string('csrf_token_hash', 128)->nullable()->after('revoke_reason');
            }
        });
    }

    public function down(): void
    {
        Schema::table('user_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('user_sessions', 'csrf_token_hash')) {
                $table->dropColumn('csrf_token_hash');
            }
        });
    }
};
