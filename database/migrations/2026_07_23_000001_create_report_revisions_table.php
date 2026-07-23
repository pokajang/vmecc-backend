<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_id')->constrained('reports')->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->json('payload');
            $table->string('payload_checksum', 64);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['report_id', 'revision'], 'report_revisions_report_id_revision_unique');
            $table->index('report_id', 'report_revisions_report_id_idx');
            $table->index('created_by', 'report_revisions_created_by_idx');
            $table->index('created_at', 'report_revisions_created_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_revisions');
    }
};
