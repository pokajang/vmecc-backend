<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_helper_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 140);
            $table->string('source_filename');
            $table->string('source_mime', 120)->default('application/pdf');
            $table->unsignedBigInteger('source_size')->nullable();
            $table->string('source_path')->nullable();
            $table->string('source_hash', 64)->nullable()->index();
            $table->string('visibility', 16)->default('personal')->index();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['uploaded_by', 'created_at']);
            $table->index(['visibility', 'created_at']);
        });

        Schema::table('ai_helper_knowledge_entries', function (Blueprint $table) {
            $table->foreignId('source_document_id')
                ->nullable()
                ->after('uploaded_by')
                ->constrained('ai_helper_documents')
                ->nullOnDelete();
        });

        $documentIdsByKey = [];
        $pdfEntries = DB::table('ai_helper_knowledge_entries')
            ->whereRaw('LOWER(COALESCE(source_mime, ?)) = ?', ['', 'application/pdf'])
            ->get();

        foreach ($pdfEntries as $entry) {
            $documentId = DB::table('ai_helper_documents')->insertGetId([
                'uploaded_by' => $entry->uploaded_by,
                'title' => mb_substr((string) ($entry->title ?: pathinfo((string) $entry->source_filename, PATHINFO_FILENAME)), 0, 140),
                'source_filename' => (string) ($entry->source_filename ?: 'document.pdf'),
                'source_mime' => 'application/pdf',
                'source_size' => $entry->source_size,
                'source_path' => $entry->source_path,
                'source_hash' => null,
                'visibility' => in_array($entry->visibility, ['personal', 'shared'], true) ? $entry->visibility : 'shared',
                'acknowledged_at' => $entry->acknowledged_at,
                'created_at' => $entry->created_at,
                'updated_at' => $entry->updated_at,
                'deleted_at' => $entry->deleted_at,
            ]);

            foreach ([$entry->title, $entry->source_filename] as $candidate) {
                $key = $this->documentKey((string) $candidate);
                if ($key !== '') {
                    $documentIdsByKey[$key] = $documentId;
                }
            }
        }

        $markdownEntries = DB::table('ai_helper_knowledge_entries')
            ->whereRaw('LOWER(COALESCE(source_mime, ?)) IN (?, ?)', ['', 'text/markdown', 'text/plain'])
            ->get(['id', 'title', 'source_filename']);

        foreach ($markdownEntries as $entry) {
            $documentId = null;
            foreach ([$entry->source_filename, $entry->title] as $candidate) {
                $key = $this->documentKey((string) $candidate);
                if ($key !== '' && isset($documentIdsByKey[$key])) {
                    $documentId = $documentIdsByKey[$key];
                    break;
                }
            }

            if ($documentId !== null) {
                DB::table('ai_helper_knowledge_entries')
                    ->where('id', $entry->id)
                    ->update(['source_document_id' => $documentId]);
            }
        }

        if ($pdfEntries->isNotEmpty()) {
            DB::table('ai_helper_knowledge_entries')
                ->whereIn('id', $pdfEntries->pluck('id'))
                ->delete();
        }
    }

    public function down(): void
    {
        Schema::table('ai_helper_knowledge_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_document_id');
        });

        Schema::dropIfExists('ai_helper_documents');
    }

    private function documentKey(string $value): string
    {
        $value = pathinfo(trim($value), PATHINFO_FILENAME);
        $value = mb_strtolower($value);

        return trim((string) preg_replace('/[^a-z0-9]+/i', '-', $value), '-');
    }
};
