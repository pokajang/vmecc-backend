<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_helper_runs', function (Blueprint $table) {
            $table->string('source_mode', 16)->nullable()->after('language');
            $table->json('operation_keys')->nullable()->after('topic_keys');
            $table->unsignedSmallInteger('coverage_supported_count')->default(0)->after('evidence_sources');
            $table->unsignedSmallInteger('coverage_missing_count')->default(0)->after('coverage_supported_count');
            $table->string('retrieval_failure_reason', 48)->nullable()->after('coverage_missing_count');
            $table->string('validation_failure_reason', 64)->nullable()->after('verification_status');
            $table->string('fallback_type', 32)->nullable()->after('validation_failure_reason');
        });
    }

    public function down(): void
    {
        Schema::table('ai_helper_runs', function (Blueprint $table) {
            $table->dropColumn([
                'source_mode',
                'operation_keys',
                'coverage_supported_count',
                'coverage_missing_count',
                'retrieval_failure_reason',
                'validation_failure_reason',
                'fallback_type',
            ]);
        });
    }
};
