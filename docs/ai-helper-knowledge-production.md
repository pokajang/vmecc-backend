# Ask AI knowledge production runbook

## Release gates

The release is ready only when this command exits successfully:

```bash
php artisan ai-helper:knowledge-readiness
```

Use `--json` in automated deployment checks.

## Runtime prerequisites

- `pdftotext`, `pdfinfo`, and `pdftoppm` must be on the application and worker `PATH`.
- Tesseract must be installed with the `eng` and `msa` language packs.
- PHP GD with PNG support must be enabled for blank/visual-page detection.
- The production queue must not use the `sync` driver.
- `QUEUE_RETRY_AFTER` must be greater than `AI_HELPER_KNOWLEDGE_JOB_TIMEOUT_SECONDS`.
- A continuously supervised queue worker and Laravel scheduler must be running.
- The worker user needs read/write access to `storage/app/ai-helper`.

Recommended worker settings for the supplied production defaults:

```bash
php artisan queue:work --tries=3 --timeout=900
```

Use the hosting control panel, process supervisor, or release runner when direct SSH is unavailable.

## Deployment sequence

```bash
php artisan migrate --force
php artisan config:cache
php artisan queue:restart
php artisan ai-helper:knowledge-readiness
```

The readiness command is expected to fail with `pending_reindex` after the first deployment of page-quality tracking. Re-index after all runtime checks pass:

```bash
php artisan ai-helper:reindex-knowledge
```

Wait for the queue to drain, then run readiness again. For a controlled maintenance run, `--sync` processes documents in the foreground and returns a non-zero exit code if any document fails or requires review.

## Review-required documents

Documents remain inactive when OCR cannot establish complete meaning, including visually dense maps or diagrams where OCR extracts labels but not relationships. Add administrator-reviewed companion Markdown that explains the visual content, then replace or remove the incomplete PDF entry after the companion source is approved. Do not activate incomplete entries manually.

The current repository corpus identifies these sources for content review:

- `ANNEX 3 VMM Area Layout.pdf`
- `ANNEX 4 Potential Emergency Scenario by Zone.pdf`
- `ANNEX 29 Emergency Reporting Format.pdf`

## Failure and rollback behavior

- New incomplete documents are stored for audit but remain inactive.
- A failed re-index keeps the previous active index and records an actionable error.
- Chunk and page-audit replacement occurs in one database transaction.
- Stale OCR temporary directories and retained failed uploads are pruned by the scheduled `ai-helper:prune-knowledge-files` command.
