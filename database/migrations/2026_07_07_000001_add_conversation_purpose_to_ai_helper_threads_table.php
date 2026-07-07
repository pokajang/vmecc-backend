<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_helper_threads', function (Blueprint $table) {
            if (! Schema::hasColumn('ai_helper_threads', 'conversation_purpose')) {
                $table->string('conversation_purpose', 40)->nullable()->after('title');
                $table->index(
                    ['user_id', 'conversation_purpose', 'updated_at'],
                    'ai_helper_threads_user_purpose_updated_idx'
                );
            }
        });

        DB::table('ai_helper_threads')
            ->whereNull('conversation_purpose')
            ->where(function ($query) {
                $query
                    ->where('title', 'like', 'Translate And Polish This General/Hse Inspection%')
                    ->orWhere('title', 'like', 'Generate An Erco Emergency Response Incident Summary%')
                    ->orWhere('title', 'like', 'Improve The Existing Erco Emergency Response Incident Summary%')
                    ->orWhere('title', 'like', 'Check This Erco Report For Possible Missing%');
            })
            ->delete();

        DB::table('ai_helper_threads')
            ->whereNull('conversation_purpose')
            ->update(['conversation_purpose' => 'chat']);
    }

    public function down(): void
    {
        Schema::table('ai_helper_threads', function (Blueprint $table) {
            if (Schema::hasColumn('ai_helper_threads', 'conversation_purpose')) {
                $table->dropIndex('ai_helper_threads_user_purpose_updated_idx');
                $table->dropColumn('conversation_purpose');
            }
        });
    }
};
