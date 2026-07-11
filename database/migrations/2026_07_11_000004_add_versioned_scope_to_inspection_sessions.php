<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inspection_sessions', function (Blueprint $table): void {
            $table->string('scope_version', 24)->default('legacy')->after('status')->index();
            $table->string('scope_key', 190)->nullable()->after('scope_version')->index();
        });

        Schema::create('inspection_session_scope_claims', function (Blueprint $table): void {
            $table->id();
            $table->string('scope_key', 190)->unique();
            $table->foreignId('inspection_session_id')->unique()->constrained('inspection_sessions')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_session_scope_claims');
        Schema::table('inspection_sessions', function (Blueprint $table): void {
            $table->dropIndex(['scope_version']);
            $table->dropIndex(['scope_key']);
            $table->dropColumn(['scope_version', 'scope_key']);
        });
    }
};
