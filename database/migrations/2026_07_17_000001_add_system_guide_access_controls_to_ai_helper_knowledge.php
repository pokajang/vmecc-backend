<?php

use App\Models\AiHelperKnowledgeEntry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_helper_knowledge_entries', function (Blueprint $table) {
            $table->string('knowledge_type', 32)
                ->default(AiHelperKnowledgeEntry::KNOWLEDGE_UPLOADED_MARKDOWN)
                ->after('source_document_id');
            $table->json('required_permissions')->nullable()->after('knowledge_type');
            $table->string('permission_match', 8)
                ->default(AiHelperKnowledgeEntry::PERMISSION_MATCH_ANY)
                ->after('required_permissions');
            $table->json('allowed_roles')->nullable()->after('permission_match');
            $table->string('module_gate', 120)->nullable()->after('allowed_roles');
            $table->string('guide_owner', 120)->nullable()->after('module_gate');
            $table->timestamp('review_due_at')->nullable()->after('guide_owner');

            $table->index(
                ['knowledge_type', 'active', 'review_status'],
                'ai_knowledge_type_active_review_idx',
            );
            $table->index(['module_gate', 'active'], 'ai_module_gate_active_idx');
            $table->index(
                ['module_key', 'route_key', 'knowledge_type'],
                'ai_module_route_type_idx',
            );
        });

        DB::table('ai_helper_knowledge_entries')
            ->whereNotNull('source_document_id')
            ->update(['knowledge_type' => AiHelperKnowledgeEntry::KNOWLEDGE_REFERENCE_DOCUMENT]);

        DB::table('ai_helper_knowledge_entries')
            ->where('source_path', 'like', 'seed:ai_knowledge:%')
            ->update(['knowledge_type' => AiHelperKnowledgeEntry::KNOWLEDGE_REFERENCE_DOCUMENT]);

        DB::table('ai_helper_knowledge_entries')
            ->whereNull('source_document_id')
            ->where('source_path', 'like', 'seed:%')
            ->where('source_path', 'not like', 'seed:ai_knowledge:%')
            ->update([
                'knowledge_type' => AiHelperKnowledgeEntry::KNOWLEDGE_SYSTEM_GUIDE,
                'status' => AiHelperKnowledgeEntry::STATUS_DISABLED,
                'active' => false,
            ]);
    }

    public function down(): void
    {
        Schema::table('ai_helper_knowledge_entries', function (Blueprint $table) {
            $table->dropIndex('ai_knowledge_type_active_review_idx');
            $table->dropIndex('ai_module_gate_active_idx');
            $table->dropIndex('ai_module_route_type_idx');
            $table->dropColumn([
                'knowledge_type',
                'required_permissions',
                'permission_match',
                'allowed_roles',
                'module_gate',
                'guide_owner',
                'review_due_at',
            ]);
        });
    }
};
