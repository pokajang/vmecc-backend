<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_notifications', function (Blueprint $table) {
            $table->string('category', 80)->nullable()->after('action_required');
            $table->string('severity', 40)->nullable()->after('category');
            $table->string('channel_policy', 80)->nullable()->after('severity');
            $table->string('dedupe_key', 190)->nullable()->after('channel_policy');
            $table->timestamp('updated_at')->nullable()->after('created_at');

            $table->index(['category', 'resolved_at'], 'workflow_notifications_category_resolved_idx');
            $table->index(['channel_policy', 'resolved_at'], 'workflow_notifications_channel_resolved_idx');
            $table->index(['dedupe_key', 'resolved_at'], 'workflow_notifications_dedupe_resolved_idx');
        });

        DB::table('workflow_notifications')
            ->whereNull('updated_at')
            ->update([
                'updated_at' => DB::raw('COALESCE(created_at, CURRENT_TIMESTAMP)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('workflow_notifications', function (Blueprint $table) {
            $table->dropIndex('workflow_notifications_category_resolved_idx');
            $table->dropIndex('workflow_notifications_channel_resolved_idx');
            $table->dropIndex('workflow_notifications_dedupe_resolved_idx');

            $table->dropColumn([
                'category',
                'severity',
                'channel_policy',
                'dedupe_key',
                'updated_at',
            ]);
        });
    }
};
