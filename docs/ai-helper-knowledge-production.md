# Ask AI knowledge production runbook

## Operating model

Ask AI uses private, administrator-approved Markdown as its only knowledge source. PDFs are reference documents for authenticated users to view and download; uploading a PDF never creates knowledge chunks and never invokes OCR or text extraction.

The bundled reference corpus pairs each private Markdown source with its corresponding PDF by exact basename. AI citations use the linked PDF document rather than exposing the Markdown source.

## Release gates

The release is ready only when this command exits successfully:

```bash
php artisan ai-helper:knowledge-readiness
```

Use `--json` in automated deployment checks and require:

- `ready: true`
- `runtime.mode: markdown_only`
- `runtime.pdf_ingestion_enabled: false`
- `runtime.external_ocr_required: false`
- zero processing or failed Markdown sources

## Runtime prerequisites

- No Poppler or Tesseract installation is required.
- PDFs and Markdown must remain on Laravel's private `local` filesystem disk.
- Provision the approved `ai_knowledge/` source directory outside the web root and set `AI_HELPER_REFERENCE_CORPUS_PATH` to its absolute path. The source corpus is intentionally not committed to this repository.
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
php artisan ai-helper:reindex-knowledge
php artisan ai-helper:knowledge-readiness
```

For controlled maintenance where no worker is available:

```bash
php artisan ai-helper:reindex-knowledge --sync
php artisan ai-helper:knowledge-readiness
```

## Administration and privacy

- Only system administrators can upload, review metadata for, update, or delete Markdown knowledge.
- User-facing routes expose reference-document metadata and authenticated PDF file access only.
- Admin review responses do not return Markdown content, chunks, or private storage paths.
- Keep both source types outside the public web root. Files are served only through authorized controller actions.
- Source illegibility or visual interpretation must be resolved in the reviewed Markdown before deployment; the application does not attempt OCR or infer meaning from uploaded PDFs.

## Failure and rollback behavior

- Failed or processing Markdown makes the readiness command fail.
- A failed re-index preserves the stored source and records an actionable error.
- The previous active chunks remain available until a replacement run completes successfully.
- Retained failed uploads are pruned by the scheduled `ai-helper:prune-knowledge-files` command.
- Rolling back application code does not roll back the database migration automatically; preserve a database backup before `php artisan migrate --force`.
