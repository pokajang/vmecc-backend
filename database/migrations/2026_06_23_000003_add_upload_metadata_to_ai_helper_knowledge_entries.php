<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_helper_knowledge_entries', function (Blueprint $table) {
            $table->foreignId('uploaded_by')->nullable()->after('id')->constrained('users')->nullOnDelete();
            $table->string('source_filename')->nullable()->after('content');
            $table->string('source_mime', 120)->nullable()->after('source_filename');
            $table->unsignedBigInteger('source_size')->nullable()->after('source_mime');
            $table->string('source_path')->nullable()->after('source_size');
            $table->string('scope_type', 16)->nullable()->after('source_path')->index();
            $table->string('status', 24)->default('active')->after('scope_type')->index();
            $table->timestamp('acknowledged_at')->nullable()->after('status');
            $table->text('error')->nullable()->after('acknowledged_at');
            $table->softDeletes();

            $table->index(['uploaded_by', 'created_at']);
            $table->index(['active', 'status', 'module_key', 'route_key']);
        });
    }

    public function down(): void
    {
        Schema::table('ai_helper_knowledge_entries', function (Blueprint $table) {
            $table->dropIndex(['uploaded_by', 'created_at']);
            $table->dropIndex(['active', 'status', 'module_key', 'route_key']);
            $table->dropSoftDeletes();
            $table->dropConstrainedForeignId('uploaded_by');
            $table->dropColumn([
                'source_filename',
                'source_mime',
                'source_size',
                'source_path',
                'scope_type',
                'status',
                'acknowledged_at',
                'error',
            ]);
        });
    }
};
