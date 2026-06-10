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
        Schema::table('message_thread_hidden', function (Blueprint $table) {
            $table->renameColumn('hidden_at', 'hidden_before');
        });
    }

    public function down(): void
    {
        Schema::table('message_thread_hidden', function (Blueprint $table) {
            $table->renameColumn('hidden_before', 'hidden_at');
        });
    }
};
