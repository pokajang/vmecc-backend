# Ask AI knowledge production runbook

## Operating model

Ask AI uses private, administrator-approved Markdown as its only knowledge source. PDFs are reference documents for authenticated users to view and download; uploading a PDF never creates knowledge chunks and never invokes OCR or text extraction.

The bundled reference corpus pairs each private Markdown source with its corresponding PDF by exact basename. AI citations use the linked PDF document rather than exposing the Markdown source.

## Release gates

The release is ready only when this command exits successfully:

```bash
php artisan ai-helper:knowledge-readiness
```

This is the UAT/runtime gate and permits grounding verification in `shadow` mode. Before production traffic is enabled, the stricter fail-closed gate must also succeed:

```bash
php artisan ai-helper:knowledge-readiness --production
```

Use `--json` in automated deployment checks and require:

- `ready: true`
- `runtime.mode: markdown_only`
- `runtime.pdf_ingestion_enabled: false`
- `runtime.external_ocr_required: false`
- zero processing or failed Markdown sources
- `retrieval.mode: hybrid`
- `retrieval.pipeline_version: 3`
- `retrieval.verification_configuration_valid: true`
- `retrieval.retrieval_configuration_valid: true`
- `retrieval.missing_embeddings: 0` before enabling semantic UAT

The production gate additionally requires a configured AI provider, retrieval v3, semantic coverage, reranking, citation and critical-fact validation, and `AI_HELPER_GROUNDING_VERIFICATION_MODE=enforce`. An empty, unapproved, inactive, unlinked, processing, or failed corpus fails both gates.

## Runtime prerequisites

- No Poppler or Tesseract installation is required.
- PDFs and Markdown must remain on Laravel's private `local` filesystem disk.
- Provision the approved `ai_knowledge/` source directory outside the web root and set `AI_HELPER_REFERENCE_CORPUS_PATH` to its absolute path. The source corpus is intentionally not committed to this repository.
- The corpus layout must contain PDFs under `ai_knowledge/pdf/` and their exact-basename Markdown counterparts under `ai_knowledge/md/`. Audit and image assets may remain under `md/`; the seeder reads only the 34 top-level Markdown files.
- A queue worker is recommended for administrator Markdown uploads and re-indexing; the small bundled corpus can also be seeded or re-indexed synchronously during controlled maintenance.
- The worker user needs read/write access to `storage/app/ai-helper`.
- The normal Laravel scheduler and queue health checks still apply to the rest of the application.

## Deployment sequence

```bash
php artisan migrate --force
php artisan db:seed --class=AiHelperReferenceCorpusSeeder --force
php artisan config:cache
php artisan queue:restart
php artisan ai-helper:knowledge-readiness
```

The corpus seeder is idempotent. It creates or updates one private Markdown knowledge entry and one view-only PDF document for every exact source pair, without ingesting the PDF. It fails closed if the configured directory or a matching Markdown file is missing.

If retained Markdown sources need their chunks rebuilt, queue the re-index and wait for the worker to drain:

```bash
php artisan ai-helper:reindex-knowledge --semantic
php artisan ai-helper:knowledge-readiness
```

For controlled maintenance where no worker is available:

```bash
php artisan ai-helper:reindex-knowledge --sync --semantic
php artisan ai-helper:knowledge-readiness
```

Run the 14-case deterministic factual/safety benchmark and the 136-case corpus-wide retrieval matrix after seeding or retrieval changes:

```bash
php artisan ai-helper:evaluate-knowledge --suite=core
php artisan ai-helper:evaluate-knowledge --suite=coverage
```

On UAT, use `--live` to grade the configured response model for required facts, revision handling, citations, visual references, follow-up context, and safe not-found behavior. The command does not create chat threads or persist answers:

```bash
php artisan ai-helper:evaluate-knowledge --suite=core --live
```

Use `--suite=all` to run all 150 cases together and `--case=<case-id>` one or more times to isolate a failed case. Coverage cases are retrieval-only even when `--live` is supplied so the live gate does not make 136 unnecessary response-model calls. The maintained core benchmark includes English, Bahasa Melayu, mixed-language, exact revision, revision-conflict, cross-document, visual-reference, follow-up, and safe credential-request coverage.

## Grounded response gate

`AI_HELPER_CITATION_VALIDATION_ENABLED=true` buffers each generated answer until its Markdown blocks and list groups have been checked against the retrieved source IDs. Operational answers with missing citations or unknown source IDs are not emitted. They are replaced with a safe insufficient-evidence response, their visible source list is cleared, and the validation result is recorded under the message retrieval metadata.

Deterministic catalogue responses and responses for which no knowledge passage was retrieved do not require citations. Because validation occurs before the first answer delta, the UI continues to show its loading state until the complete grounded answer is ready.

## Retrieval v3 and answer verification

Retrieval v3 searches all eligible Markdown entries, fuses lexical, semantic, document-identity, and heading rankings, and optionally reranks a bounded candidate set before assembling the final evidence prompt. Provider-assisted reranking fails open to the deterministic fused order; it never makes Ask AI unavailable.

Critical telephone numbers, timings, quantities, thresholds, and document codes are checked deterministically against the cited evidence. The grounding verifier then checks claim support, contradiction, missing qualifiers, completeness, and revision attribution. A failed draft receives one repair attempt. A second failure returns the safe insufficient-evidence response and clears visible sources.

Recommended staged configuration:

```ini
AI_HELPER_RETRIEVAL_V3=true
AI_HELPER_KNOWLEDGE_DOCUMENT_CANDIDATE_LIMIT=12
AI_HELPER_RETRIEVAL_CANDIDATE_CHUNKS=40
AI_HELPER_RETRIEVAL_MIN_LEXICAL_COVERAGE=0.6
AI_HELPER_RETRIEVAL_MIN_SEMANTIC_SIMILARITY=0.42
AI_HELPER_RERANK_ENABLED=true
AI_HELPER_RERANK_CANDIDATE_LIMIT=32
AI_HELPER_RERANK_MIN_RELEVANCE=1
AI_HELPER_CRITICAL_FACT_VALIDATION_ENABLED=true
AI_HELPER_GROUNDING_VERIFICATION_MODE=shadow
AI_HELPER_VERIFICATION_MAX_ATTEMPTS=2
```

Use `shadow` during initial UAT. The verifier records failures without blocking responses. After the core live suite and reviewed UAT conversations pass, change the mode to `enforce`, run `php artisan config:cache`, and restart queue workers. In enforce mode the verifier fails closed.

After switching to `enforce`, run `php artisan ai-helper:knowledge-readiness --production --json` and require `ready: true`, `release_gate: production`, and `retrieval.production_configuration_valid: true` before opening production traffic.

Retrieval v3 uses the existing message `retrieval_metadata` JSON column and requires no additional database migration. To roll back only v3 behavior, set `AI_HELPER_RETRIEVAL_V3=false`, `AI_HELPER_RERANK_ENABLED=false`, and `AI_HELPER_GROUNDING_VERIFICATION_MODE=disabled`, then rebuild cached configuration.

## Administration and privacy

- Only system administrators can upload, review metadata for, update, or delete Markdown knowledge.
- User-facing routes expose reference-document metadata and authenticated PDF file access only.
- Admin review responses do not return Markdown content, chunks, or private storage paths.
- Keep both source types outside the public web root. Files are served only through authorized controller actions.
- Source illegibility or visual interpretation must be resolved in the reviewed Markdown before deployment; the application does not attempt OCR or infer meaning from uploaded PDFs.
- Ordinary Markdown chunks do not claim a PDF page number. Page-specific links are emitted only when the source-visible Markdown identifies the original PDF page.
- Embedding vectors are stored in the private application database. The Markdown corpus is not uploaded to a persistent hosted vector store.

## Failure and rollback behavior

- Failed or processing Markdown makes the readiness command fail.
- A failed re-index preserves the stored source and records an actionable error.
- The previous active chunks remain available until a replacement run completes successfully.
- Retained failed uploads are pruned by the scheduled `ai-helper:prune-knowledge-files` command.
- Rolling back application code does not roll back the database migration automatically; preserve a database backup before `php artisan migrate --force`.
