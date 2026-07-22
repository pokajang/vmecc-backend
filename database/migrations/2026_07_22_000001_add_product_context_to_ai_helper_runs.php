<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_helper_runs', function (Blueprint $table) {
            $table->string('answer_mode', 32)->nullable()->after('source_mode');
            $table->string('workflow_key', 96)->nullable()->after('answer_mode');
        });
    }

    public function down(): void
    {
        Schema::table('ai_helper_runs', function (Blueprint $table) {
            $table->dropColumn(['answer_mode', 'workflow_key']);
        });
    }
};
