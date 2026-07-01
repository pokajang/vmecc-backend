<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_helper_knowledge_entries', function (Blueprint $table) {
            $table->text('summary')->nullable()->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('ai_helper_knowledge_entries', function (Blueprint $table) {
            $table->dropColumn('summary');
        });
    }
};
