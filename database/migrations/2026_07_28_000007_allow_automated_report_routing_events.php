<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_routing_events', function (Blueprint $table) {
            $table->foreignId('created_by_user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('report_routing_events')->whereNull('created_by_user_id')->delete();

        Schema::table('report_routing_events', function (Blueprint $table) {
            $table->foreignId('created_by_user_id')->nullable(false)->change();
        });
    }
};
