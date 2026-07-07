<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_email_deliveries', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('notification_id')->constrained('users')->nullOnDelete();
            $table->string('delivery_kind', 40)->default('immediate')->after('recipient_email');
            $table->timestamp('digest_window_start')->nullable()->after('delivery_kind');
            $table->timestamp('digest_window_end')->nullable()->after('digest_window_start');

            $table->index(['user_id', 'delivery_kind'], 'workflow_email_deliveries_user_kind_idx');
            $table->index(['delivery_kind', 'digest_window_start'], 'workflow_email_deliveries_kind_window_idx');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_email_deliveries', function (Blueprint $table) {
            $table->dropIndex('workflow_email_deliveries_user_kind_idx');
            $table->dropIndex('workflow_email_deliveries_kind_window_idx');

            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn([
                'delivery_kind',
                'digest_window_start',
                'digest_window_end',
            ]);
        });
    }
};
