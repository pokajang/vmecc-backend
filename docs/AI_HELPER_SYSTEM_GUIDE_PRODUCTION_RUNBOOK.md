# AI Helper final system-guide production runbook

This runbook deploys the complete 34-reference and 53-system-guide corpus. It must not be used while `php artisan ai-helper:system-guides:audit` reports any draft, inactive, non-v3, missing, invalid, maintainer-oriented, or unverified guide.

## Release record

Fill these values in the release record before deployment:

```text
BACKEND_COMMIT=<backend commit SHA>
FRONTEND_COMMIT=<frontend commit SHA>
UAT_REFERENCE=<UAT/change reference>
```

Production `.env` must contain:

```dotenv
QUEUE_CONNECTION=database
QUEUE_RETRY_AFTER=960
AI_HELPER_SYSTEM_GUIDES_ENABLED=true
AI_HELPER_SYSTEM_GUIDE_FINAL_CORPUS_ENFORCED=true
AI_HELPER_SYSTEM_GUIDE_APPROVAL_ENFORCED=true
AI_HELPER_RETRIEVAL_V2=true
AI_HELPER_RETRIEVAL_V3=true
AI_HELPER_RETRIEVAL_V4=true
AI_HELPER_PIPELINE_VERSION=4
AI_HELPER_INDEX_PROFILE_VERSION=4
AI_HELPER_KNOWLEDGE_STRICT_READINESS=true
AI_HELPER_EMBEDDING_ENABLED=true
AI_HELPER_RERANK_ENABLED=true
AI_HELPER_CITATION_VALIDATION_ENABLED=true
AI_HELPER_CRITICAL_FACT_VALIDATION_ENABLED=true
AI_HELPER_GROUNDING_VERIFICATION_MODE=enforce
AI_HELPER_REQUEST_DEADLINE_SECONDS=50
AI_HELPER_MAX_PROVIDER_CALLS_PER_REQUEST=8
AI_HELPER_CONCURRENCY_LOCK_SECONDS=90
```

`QUEUE_RETRY_AFTER` must remain greater than the longest `--timeout=900` indexing worker/job timeout so a slow shared-host job cannot be reserved twice.

Do not print the `.env` or provider key in terminal logs.

## Preflight and pull

Run from the backend repository after replacing the expected SHA:

```bash
set -euo pipefail

EXPECTED_BACKEND_COMMIT='<approved-backend-sha>'
test -z "$(git status --porcelain)"
git fetch origin main
git merge --ff-only origin/main
test "$(git rev-parse HEAD)" = "$EXPECTED_BACKEND_COMMIT"
php -v
php ~/composer.phar --version
php artisan ai-helper:system-guides:audit --json
```

The final audit command must exit successfully before maintenance begins. A disabled candidate corpus is expected to fail this audit and must not be deployed.

## Backup

Use the hosting provider's approved backup mechanism. Record the backup identifier, timestamp, database, size, and restore verification in the change record. Do not continue without a verified backup.

## One-go backend deployment block

This block deliberately leaves the application in maintenance mode if any command fails:

```bash
set -euo pipefail

php artisan down --retry=60
php ~/composer.phar install --no-dev --no-interaction --prefer-dist --optimize-autoloader
php artisan optimize:clear
php artisan migrate:status
php artisan migrate --force
php artisan db:seed --class=AiHelperReferenceCorpusSeeder --force
php artisan db:seed --class=AiHelperSystemGuideSeeder --force

php artisan config:cache
php artisan route:cache
php artisan view:cache

php artisan ai-helper:system-guides:audit --json
php artisan ai-helper:storage-health --json
php artisan ai-helper:reindex-knowledge --semantic
php artisan queue:work database --stop-when-empty --force --tries=3 --timeout=900
php artisan queue:restart

php artisan ai-helper:reconcile-stale-streams --dry-run
php artisan ai-helper:reconcile-stuck-embeddings --dry-run
php artisan ai-helper:storage-health --json
php artisan ai-helper:knowledge-readiness --production --json
php artisan ai-helper:evaluate-knowledge --suite=core --json
php artisan ai-helper:evaluate-knowledge --suite=coverage --json
php artisan ai-helper:evaluate-knowledge --suite=system-guide-core --actor-map=/secure/path/ai-helper-uat-actors.json --json
php artisan ai-helper:evaluate-knowledge --suite=system-guide-coverage --actor-map=/secure/path/ai-helper-uat-actors.json --json
php artisan ai-helper:evaluate-knowledge --suite=system-guide-global --actor-map=/secure/path/ai-helper-uat-actors.json --json

php artisan up
```

Do not add `php artisan up` to an unconditional cleanup handler. Retrieval V4 changes chunk and routing-vector fingerprints, so its first deployment must run the full semantic rebuild shown above. Do not add `--only-missing`: rows carrying legacy vectors can still be marked `ready`.

Replacement chunks remain inactive until their complete semantic index succeeds. A failed replacement retains the entry's previous active chunks for runtime continuity, but the production readiness gate remains red until that entry is rebuilt successfully.

## Required readiness result

Before reopening traffic, require all of these values:

```text
ready: true
release_gate: production
reference_knowledge_ready: true
system_guides_ready: true
role_aware_retrieval_ready: true
system_guides_runtime_enabled: true
final_corpus_enforced: true
deployment_state: production_ready
retrieval.pipeline_version: 4
retrieval.schema_ready: true
retrieval.production_configuration_valid: true
retrieval.index_fingerprint: <current configured fingerprint>
reference_knowledge.total: 34
reference_knowledge.active: 34
reference_knowledge.embedded: 34
reference_knowledge.missing_embeddings: 0
reference_knowledge.compatible_embeddings: 34
reference_knowledge.incompatible_embeddings: 0
system_guides.total: 53
system_guides.active: 53
system_guides.approved: 53
system_guides.expected_versions: 53
system_guides.source_final: 53
system_guides.source_active: 53
system_guides.source_hash_matches: 53
system_guides.verification_dossiers: 53
system_guides.processing: 0
system_guides.failed: 0
system_guides.embedded: 53
system_guides.missing_embeddings: 0
system_guides.compatible_embeddings: 53
system_guides.incompatible_embeddings: 0
system_guides.legacy_active: 0
system_guides.catalog_errors: []
```

## Frontend deployment

Pull and verify the approved frontend SHA using the hosting provider's documented frontend directory, then install from the lockfile and build production assets:

```bash
set -euo pipefail

EXPECTED_FRONTEND_COMMIT='<approved-frontend-sha>'
test -z "$(git status --porcelain)"
git fetch origin main
git merge --ff-only origin/main
test "$(git rev-parse HEAD)" = "$EXPECTED_FRONTEND_COMMIT"
npm ci
npm audit --audit-level=high
npm run lint
npx vitest run --environment jsdom
npm run build -- --mode production
```

Verify the deployed assets contain the production API URL, no localhost API URL, and the required web-server configuration file.

## Smoke checks

Use existing UAT/production accounts; do not create users in the deployment:

1. ordinary self-service user receives an authorized self-service guide and no management guide;
2. HR user receives scoped staff/leave/overtime guidance only;
3. Finance manager cannot retrieve payment actions without `staff.salary.pay`;
4. Operations user receives the expected report/inspection/team/roster guide within scope;
5. System Administrator receives admin guidance but still cannot retrieve a disabled-module guide;
6. a PDF citation opens the authorized page fragment;
7. a system-guide citation is non-clickable and displays `VMECC System Guide` with version 3;
8. forged route/module context does not leak a forbidden title or source ID.
9. explicit leave, overtime, payroll, and inspection questions retrieve the authorized global guide even while the user is on an unrelated page;
10. vague questions such as "what can I do here?" use the current page only as a ranking hint, not as an authorization boundary.

Record results against the exact backend SHA, frontend SHA, seeded content hashes, and UAT reference.

## Rollback and failed gate

If any gate or smoke check fails:

1. leave the application in maintenance mode;
2. set `AI_HELPER_SYSTEM_GUIDES_ENABLED=false` in the server-only `.env`;
3. run `php artisan optimize:clear`, `php artisan config:cache`, and `php artisan queue:restart`;
4. investigate the failing migration, seed, embedding, authorization, evaluator, content, or readiness evidence;
5. restore the verified database backup when database rollback is required;
6. reopen traffic only after confirming the unchanged 34-reference corpus is ready and system guides are excluded.

Never use `git reset --hard`, migration reset, or repository rollback as a substitute for a verified database restore.

For a Retrieval V4-only runtime rollback, set `AI_HELPER_RETRIEVAL_V4=false` while retaining `AI_HELPER_RETRIEVAL_V3=true` and both final system-guide flags. Rebuild the configuration cache and restart workers. This preserves the final guide corpus under V3, but production readiness intentionally remains false until V4 is restored and revalidated.
