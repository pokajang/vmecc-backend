<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_drafts', function (Blueprint $table): void {
            $table->unsignedInteger('version')->default(1)->after('saved_at');
        });
    }

    public function down(): void
    {
        Schema::table('report_drafts', function (Blueprint $table): void {
            $table->dropColumn('version');
        });
    }
};
