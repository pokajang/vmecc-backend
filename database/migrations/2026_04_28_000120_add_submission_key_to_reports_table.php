<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->string('submission_key', 190)->nullable()->after('display_id');
            $table->unique(['owner_user_id', 'submission_key'], 'reports_owner_submission_unique');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropUnique('reports_owner_submission_unique');
            $table->dropColumn('submission_key');
        });
    }
};

