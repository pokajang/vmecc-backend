<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_drafts', function (Blueprint $table): void {
            if (!Schema::hasColumn('report_drafts', 'saved_at')) {
                $table->timestamp('saved_at')->nullable()->after('payload');
            }
        });
    }

    public function down(): void
    {
        Schema::table('report_drafts', function (Blueprint $table): void {
            if (Schema::hasColumn('report_drafts', 'saved_at')) {
                $table->dropColumn('saved_at');
            }
        });
    }
};
