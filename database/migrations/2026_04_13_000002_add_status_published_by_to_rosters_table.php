<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rosters', function (Blueprint $table) {
            // draft = saved but not yet announced; published = team has been notified
            $table->string('status')->default('draft')->after('team_id');
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete()->after('created_by');
            $table->timestamp('published_at')->nullable()->after('published_by');
        });
    }

    public function down(): void
    {
        Schema::table('rosters', function (Blueprint $table) {
            $table->dropForeignIdFor(\App\Models\User::class, 'published_by');
            $table->dropColumn(['status', 'published_by', 'published_at']);
        });
    }
};
