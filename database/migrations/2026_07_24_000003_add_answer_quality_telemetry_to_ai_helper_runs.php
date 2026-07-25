<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_helper_runs', function (Blueprint $table) {
            $table->json('task_keys')->nullable()->after('operation_keys');
            $table->json('guide_keys')->nullable()->after('task_keys');
            $table->boolean('clarification_required')->default(false)->after('guide_keys');
            $table->string('clarification_reason', 64)->nullable()->after('clarification_required');
            $table->string('record_state_used', 64)->nullable()->after('clarification_reason');
        });
    }

    public function down(): void
    {
        Schema::table('ai_helper_runs', function (Blueprint $table) {
            $table->dropColumn([
                'task_keys',
                'guide_keys',
                'clarification_required',
                'clarification_reason',
                'record_state_used',
            ]);
        });
    }
};
