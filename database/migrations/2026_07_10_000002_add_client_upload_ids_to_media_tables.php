<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_media', function (Blueprint $table) {
            $table->uuid('client_upload_id')->nullable()->after('public_id');
            $table->unique(['user_id', 'client_upload_id'], 'report_media_user_upload_unique');
        });
        Schema::table('leave_attachments', function (Blueprint $table) {
            $table->uuid('client_upload_id')->nullable()->after('id');
            $table->unique(['user_id', 'client_upload_id'], 'leave_attachment_user_upload_unique');
        });
    }

    public function down(): void
    {
        Schema::table('report_media', function (Blueprint $table) {
            $table->dropUnique('report_media_user_upload_unique');
            $table->dropColumn('client_upload_id');
        });
        Schema::table('leave_attachments', function (Blueprint $table) {
            $table->dropUnique('leave_attachment_user_upload_unique');
            $table->dropColumn('client_upload_id');
        });
    }
};
