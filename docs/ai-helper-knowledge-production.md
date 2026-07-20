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
- `reference_knowledge_ready: true`
- `system_guides_ready: true`
- `role_aware_retrieval_ready: true`
- `deployment_state: production_ready` for the production gate
- zero processing or failed Markdown sources
- `retrieval.pipeline_version: 4`
- `retrieval.index_fingerprint` matches the configured embedding model, dimensions, routing profile, and chunk profile
- `retrieval.verification_configuration_valid: true`
- `retrieval.retrieval_configuration_valid: true`
- `reference_knowledge.missing_embeddings: 0` and `system_guides.missing_embeddings: 0`
- `reference_knowledge.incompatible_embeddings: 0` and `system_guides.incompatible_embeddings: 0`

The production gate additionally requires a configured AI provider, Retrieval V4, compatible semantic coverage, final enabled system guides, reranking, citation and critical-fact validation, and `AI_HELPER_GROUNDING_VERIFICATION_MODE=enforce`. An empty, unapproved, inactive, unlinked, processing, failed, or fingerprint-incompatible corpus fails both gates.

## Runtime prerequisites

- No Poppler or Tesseract installation is required.
- PDFs and Markdown must remain on Laravel's private `local` filesystem disk.
- Provision the approved `ai_knowledge/` source directory outside the web root and set `AI_HELPER_REFERENCE_CORPUS_PATH` to its absolute path. The source corpus is intentionally not committed to this repository.
- The corpus layout must contain PDFs under `ai_knowledge/pdf/` and their exact-basename Markdown counterparts under `ai_knowledge/md/`. Audit and image assets may remain under `md/`; the seeder reads only the 34 top-level Markdown files.
- A database queue worker is required during queued Markdown uploads and re-indexing; the small bundled corpus can also be rebuilt synchronously during controlled maintenance.
- The worker user needs read/write access to `storage/app/ai-helper`.
- Laravel's scheduler must run every minute. It reconciles stale streams every five minutes, retries stuck embeddings every ten minutes, and performs the configured knowledge/runtime retention jobs daily.
- On shared hosting, prevent overlapping short-lived queue workers with the host's locking facility and keep worker memory/runtime bounded.

Recommended cron entries when the host provides `flock`:

```bash
* * * * * cd ~/vmecc-backend && php artisan schedule:run >> /dev/null 2>&1
* * * * * cd ~/vmecc-backend && flock -n /tmp/vmecc-queue-worker.lock php artisan queue:work database --stop-when-empty --max-time=50 --tries=3 --timeout=900 >> /dev/null 2>&1
```

If `flock` is unavailable, configure the hosting panel so queue invocations cannot overlap.

## Deployment sequence

```bash
php artisan optimize:clear
php artisan migrate --force
php artisan db:seed --class=AiHelperReferenceCorpusSeeder --force
php artisan db:seed --class=AiHelperSystemGuideSeeder --force
php artisan config:cache
php artisan queue:restart
php artisan ai-helper:reindex-knowledge --semantic
php artisan queue:work database --stop-when-empty --tries=3 --timeout=900
php artisan ai-helper:storage-health --json
php artisan ai-helper:knowledge-readiness --production --json
```

The corpus seeder is idempotent. It creates or updates one private Markdown knowledge entry and one view-only PDF document for every exact source pair, without ingesting the PDF. It fails closed if the configured directory or a matching Markdown file is missing.

Retrieval V4 changes both passage chunking and the document routing-vector profile. Its first rollout therefore requires a **full** semantic rebuild. Do not add `--only-missing`: legacy rows may be marked ready while carrying the previous index fingerprint.

```bash
php artisan ai-helper:reindex-knowledge --semantic
php artisan queue:work database --stop-when-empty --tries=3 --timeout=900
php artisan ai-helper:knowledge-readiness
```

For controlled maintenance where no worker is available:

```bash
php artisan ai-helper:reindex-knowledge --sync --semantic
php artisan ai-helper:knowledge-readiness
```

Run reconciliation in dry-run mode before the release gate. A non-zero match must be investigated or reconciled before promotion:

```bash
php artisan ai-helper:reconcile-stale-streams --dry-run
php artisan ai-helper:reconcile-stuck-embeddings --dry-run
php artisan ai-helper:storage-health --json
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

Role-aware guide release gates require a server-only actor map. In addition to same-page access coverage, Retrieval V4 has a 114-case global suite: every one of the 53 guides is requested in English and BM from unrelated routes, followed by curated layman alias/noise cases for leave, overtime, inspection, payslips, password help, rosters, extinguisher records, and role permissions.

```bash
php artisan ai-helper:evaluate-knowledge --suite=system-guide-core --actor-map=/secure/path/ai-helper-uat-actors.json --json
php artisan ai-helper:evaluate-knowledge --suite=system-guide-coverage --actor-map=/secure/path/ai-helper-uat-actors.json --json
php artisan ai-helper:evaluate-knowledge --suite=system-guide-global --actor-map=/secure/path/ai-helper-uat-actors.json --json
```

All three commands must exit successfully. The global suite additionally requires pipeline version 4, the requested guide as the first document, and the expected explicit topic for curated aliases.

## Grounded response gate

`AI_HELPER_CITATION_VALIDATION_ENABLED=true` buffers each generated answer until its Markdown blocks and list groups have been checked against the retrieved source IDs. Operational answers with missing citations or unknown source IDs are not emitted. They are replaced with a safe insufficient-evidence response, their visible source list is cleared, and the validation result is recorded under the message retrieval metadata.

Deterministic catalogue responses and responses for which no knowledge passage was retrieved do not require citations. Because validation occurs before the first answer delta, the UI continues to show its loading state until the complete grounded answer is ready.

## Retrieval V4 and answer verification

Retrieval V4 searches the complete corpus authorized for the user. Explicit topics suppress unrelated current-page weighting; phrases such as "here" or "this page" deliberately use trusted page context. Independent exact-title, bilingual-topic, global lexical/semantic, and page-deictic lanes are combined before passage selection. One bounded page-neutral recovery search is allowed when the first pass has no relevant evidence. Provider-assisted reranking cannot remove protected exact/topic matches and falls back to deterministic fused order when it fails or returns no candidates.

Critical telephone numbers, timings, quantities, thresholds, and document codes are checked deterministically against the cited evidence. The grounding verifier then checks claim support, contradiction, missing qualifiers, completeness, and revision attribution. A failed draft receives one repair attempt. A second failure returns the safe insufficient-evidence response and clears visible sources.

All provider work for one user request shares one wall-clock deadline and one call budget, including query embeddings, reranking, generation retries, repair, and verification. Every actual HTTP attempt consumes the budget. A provider stream that ends without its completion event is rejected rather than saving a partial answer. On constrained hosting, keep the concurrency lease at least 30 seconds longer than the request deadline.

The Inspection and ERCO in-form helpers accept only named, server-validated tasks. They use strict structured output and deterministic number/date/time/identifier checks; they do not enter the global knowledge-answer pipeline and do not claim corpus grounding. Legacy free-form `embedded_helper` requests are rejected.

Recommended staged configuration:

```ini
AI_HELPER_RETRIEVAL_V3=true
AI_HELPER_RETRIEVAL_V4=true
AI_HELPER_PIPELINE_VERSION=4
AI_HELPER_INDEX_PROFILE_VERSION=4
AI_HELPER_REQUEST_DEADLINE_SECONDS=50
AI_HELPER_MAX_PROVIDER_CALLS_PER_REQUEST=8
AI_HELPER_CONCURRENCY_LOCK_SECONDS=90
AI_HELPER_KNOWLEDGE_DOCUMENT_CANDIDATE_LIMIT=12
AI_HELPER_RETRIEVAL_V4_DOCUMENT_CANDIDATE_LIMIT=18
AI_HELPER_RETRIEVAL_V4_TOPIC_CANDIDATE_LIMIT=6
AI_HELPER_RETRIEVAL_V4_PAGE_CANDIDATE_LIMIT=4
AI_HELPER_RETRIEVAL_V4_GLOBAL_CANDIDATE_LIMIT=12
AI_HELPER_RETRIEVAL_V4_RECOVERY_DOCUMENT_LIMIT=32
AI_HELPER_RETRIEVAL_CANDIDATE_CHUNKS=40
AI_HELPER_RETRIEVAL_MIN_LEXICAL_COVERAGE=0.6
AI_HELPER_RETRIEVAL_MIN_SEMANTIC_SIMILARITY=0.42
AI_HELPER_RERANK_ENABLED=true
AI_HELPER_RERANK_CANDIDATE_LIMIT=32
AI_HELPER_RERANK_MIN_RELEVANCE=1
AI_HELPER_CRITICAL_FACT_VALIDATION_ENABLED=true
AI_HELPER_GROUNDING_VERIFICATION_MODE=shadow
AI_HELPER_VERIFICATION_MAX_ATTEMPTS=2
AI_HELPER_EMBEDDING_ROUTING_PROFILE_VERSION=routing-v1
AI_HELPER_EMBEDDING_CHUNK_PROFILE_VERSION=contextual-v2
```

Use `shadow` during initial UAT. The verifier records failures without blocking responses. After the core live suite and reviewed UAT conversations pass, change the mode to `enforce`, run `php artisan config:cache`, and restart queue workers. In enforce mode the verifier fails closed.

After switching to `enforce`, run `php artisan ai-helper:knowledge-readiness --production --json` and require `ready: true`, `release_gate: production`, and `retrieval.production_configuration_valid: true` before opening production traffic.

To roll back only Retrieval V4 behaviour, set `AI_HELPER_RETRIEVAL_V4=false`, keep `AI_HELPER_RETRIEVAL_V3=true`, and keep both `AI_HELPER_SYSTEM_GUIDES_ENABLED=true` and `AI_HELPER_SYSTEM_GUIDE_FINAL_CORPUS_ENFORCED=true`. Rebuild cached configuration and restart queue workers. This retains the final role-aware guides and returns retrieval to V3; it is an emergency runtime rollback and the strict production readiness gate will remain red until V4 is restored and revalidated.

## Administration and privacy

- Only system administrators can upload, review metadata for, update, or delete Markdown knowledge.
- User-facing routes expose reference-document metadata and authenticated PDF file access only.
- Admin review responses do not return Markdown content, chunks, or private storage paths.
- Prior assistant answers are re-authorized before display and before reuse as model history. When a role, permission, module, source, or approval is revoked, the stored answer is replaced by an access-change notice and is not sent back to the provider.
- Keep both source types outside the public web root. Files are served only through authorized controller actions.
- Source illegibility or visual interpretation must be resolved in the reviewed Markdown before deployment; the application does not attempt OCR or infer meaning from uploaded PDFs.
- Ordinary Markdown chunks do not claim a PDF page number. Page-specific links are emitted only when the source-visible Markdown identifies the original PDF page.
- Embedding vectors are stored in the private application database. The Markdown corpus is not uploaded to a persistent hosted vector store.

## Failure and rollback behavior

- Failed or processing Markdown makes the readiness command fail.
- Re-indexing builds replacement chunks before activating them. A failed replacement preserves the previous active chunks for that entry, records an actionable error, and does not promote the incomplete replacement. This is the last-known-good serving path while the release gate remains a no-go.
- Readiness and the evaluation suites decide promotion; a failed new build must not be treated as production-ready merely because previous chunks can still answer requests.
- Retained failed uploads are pruned by the scheduled `ai-helper:prune-knowledge-files` command.
- `ai-helper:reconcile-stale-streams`, `ai-helper:reconcile-stuck-embeddings --retry`, and `ai-helper:prune-runtime-data` are scheduled safety nets; the scheduler cron is mandatory.
- Rolling back application code does not roll back the database migration automatically; preserve a database backup before `php artisan migrate --force`.
