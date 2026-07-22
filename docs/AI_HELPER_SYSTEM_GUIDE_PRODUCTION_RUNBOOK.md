# AI Helper final system-guide production runbook

This runbook deploys the complete 34-reference and 54-system-guide corpus. It must not be used while `php artisan ai-helper:system-guides:audit` reports any draft, inactive, non-v3, missing, invalid, maintainer-oriented, or unverified guide.

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
AI_HELPER_ENABLED=true
OPENAI_API_KEY=<server-secret>
OPENAI_HELPER_MODEL=gpt-5.4-mini
AI_HELPER_EMBEDDING_MODEL=text-embedding-3-small
```

`QUEUE_RETRY_AFTER` must remain greater than the longest `--timeout=900` indexing worker/job timeout so a slow shared-host job cannot be reserved twice.

Retrieval V4, system guides, product workflows, reranking, enforced verification, limits, and retention policy are version-controlled in `config/ai_helper.php`. Changing `OPENAI_HELPER_MODEL` changes generation, reranking, and verification together. Changing `AI_HELPER_EMBEDDING_MODEL` invalidates the semantic fingerprint and requires a complete reindex before readiness can pass.

The primary model must support streamed Responses API output and strict JSON-schema structured responses. The embedding model must accept the code-controlled 512-dimension profile. Treat either model change as a reviewed deployment and run the complete readiness and evaluation gates before reopening traffic.

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
CORPUS_PATH="storage/app/private/ai_knowledge"
test -d "$CORPUS_PATH/pdf"
test -d "$CORPUS_PATH/md"
test "$(find "$CORPUS_PATH/pdf" -maxdepth 1 -type f -iname '*.pdf' | wc -l)" -eq 34
test "$(find "$CORPUS_PATH/md" -maxdepth 1 -type f -iname '*.md' | wc -l)" -eq 34
php artisan ai-helper:system-guides:audit --json
```

The corpus path may be a deployment-managed symlink to the existing private source directory. Resolve and verify its target before deployment; it must remain outside the public web root. The directory/count checks and final audit command must exit successfully before maintenance begins. A disabled candidate corpus is expected to fail this audit and must not be deployed.

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

Deploy workflow-registry support, seed and index the matching system guides, and confirm `ai-helper:knowledge-readiness --production` before opening traffic. Product workflows are part of the code-controlled production profile rather than an independent environment switch.

After enabling it, confirm recent `ai_helper_runs` rows contain `answer_mode` and, for deterministic workflow answers, `workflow_key`. These are bounded category keys for aggregate quality monitoring; raw `ui_state` and form values must never be copied into run telemetry or stored route context.

## Rollback and failed gate

If any gate or smoke check fails:

1. leave the application in maintenance mode;
2. set `AI_HELPER_ENABLED=false` in the server-only `.env`;
3. run `php artisan optimize:clear`, `php artisan config:cache`, and `php artisan queue:restart`;
4. investigate the failing migration, seed, embedding, authorization, evaluator, content, or readiness evidence;
5. restore the verified database backup when database rollback is required;
6. reopen traffic only after confirming the unchanged 34-reference corpus is ready and system guides are excluded.

Never use `git reset --hard`, migration reset, or repository rollback as a substitute for a verified database restore.

Subsystem rollback now requires a reviewed code/config deployment. Do not reintroduce hidden environment switches for workflows, retrieval generations, or verification. `AI_HELPER_ENABLED=false` remains the immediate recoverable whole-feature shutdown while a code rollback is prepared.
