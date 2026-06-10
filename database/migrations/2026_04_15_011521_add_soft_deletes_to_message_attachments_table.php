<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('message_attachments')) {
            return;
        }
        if (Schema::hasColumn('message_attachments', 'deleted_at')) {
            return;
        }

        Schema::table('message_attachments', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('message_attachments')) {
            return;
        }
        if (!Schema::hasColumn('message_attachments', 'deleted_at')) {
            return;
        }

        Schema::table('message_attachments', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
