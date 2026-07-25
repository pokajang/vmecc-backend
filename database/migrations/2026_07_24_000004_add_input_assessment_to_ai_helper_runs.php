<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_helper_runs', function (Blueprint $table) {
            $table->string('input_decision', 32)->nullable()->after('record_state_used');
            $table->json('input_reason_codes')->nullable()->after('input_decision');
            $table->decimal('input_confidence', 4, 3)->nullable()->after('input_reason_codes');
            $table->boolean('input_recoverable')->default(false)->after('input_confidence');
            $table->boolean('input_semantic_fallback')->default(false)->after('input_recoverable');
        });
    }

    public function down(): void
    {
        Schema::table('ai_helper_runs', function (Blueprint $table) {
            $table->dropColumn([
                'input_decision',
                'input_reason_codes',
                'input_confidence',
                'input_recoverable',
                'input_semantic_fallback',
            ]);
        });
    }
};
