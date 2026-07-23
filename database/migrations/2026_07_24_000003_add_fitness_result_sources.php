<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fitness_test_participant_results', function (Blueprint $table): void {
            $table->string('fitness_result_source', 32)->nullable()->after('fitness_result')->index();
            $table->string('proficiency_result_source', 32)->nullable()->after('proficiency_result')->index();
        });
    }

    public function down(): void
    {
        Schema::table('fitness_test_participant_results', function (Blueprint $table): void {
            $table->dropIndex(['fitness_result_source']);
            $table->dropIndex(['proficiency_result_source']);
            $table->dropColumn(['fitness_result_source', 'proficiency_result_source']);
        });
    }
};
