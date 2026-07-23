# Final MVP System-Guide Production Code Change Plan

> Superseded on 2026-07-17 by `AI_HELPER_SYSTEM_GUIDE_LAYMAN_MVP_CONTRACT_2026-07-17.md`. Departmental approval metadata is no longer part of the release contract; finality is established by code-backed workflow verification, user-language validation, atomic seeding, and role-aware evaluation.

Date: 2026-07-17

Status: required corrective release plan

Supersedes the earlier remediation plan for release decisions. The earlier document remains useful as audit history, but this plan is the controlling definition of done for the final MVP system-guide release.

## 1. Required outcome

Deliver one production-ready release in which all 51 VMECC system-usage guides are:

- traced to current frontend and backend behavior;
- written as exact MVP instructions rather than generic module summaries;
- version 3;
- code-controlled;
- approved against their exact final content;
- marked `release_status: approved` and `active: true`;
- seeded, indexed, embedded, and retrievable only by the intended audience;
- absent from the public Knowledge list and unavailable as raw Markdown;
- cited as non-downloadable `VMECC System Guide` sources;
- covered by authorized, unauthorized, module-disabled, forged-context, language, and injection tests;
- included in a production readiness result where reference knowledge, system guides, and role-aware retrieval are all ready.

The release is not complete merely because the infrastructure is fail-closed. A disabled or partially authored corpus is an intermediate engineering state and must not be presented as the production MVP outcome.

## 2. Current baseline and deployment hold

Current backend baseline: `528bd06`

Current frontend baseline: `abc889c`

Current corpus state:

- 51 total Markdown files;
- 16 version 3 candidates from the global/profile/messages and leave/overtime waves;
- 35 version 2 authoring drafts;
- 0 approved guides;
- 0 active guides;
- 0 approval-manifest records;
- system-guide retrieval disabled.

Until this plan is complete:

- do not deploy the current backend or frontend commits as the final system-guide release;
- keep `AI_HELPER_SYSTEM_GUIDES_ENABLED=false` on any server that has already pulled them;
- keep `AI_HELPER_SYSTEM_GUIDE_APPROVAL_ENFORCED=true`;
- do not run `AiHelperSystemGuideSeeder` in production;
- do not edit `approvals.json` with placeholder identities or references;
- do not mark a guide approved merely to make readiness green.

All corrective work should be completed on a dedicated release branch. Intermediate local commits are allowed on that branch, but `main` must receive the correcting integration only after every guide and gate in this plan is complete.

## 3. Non-negotiable release invariants

1. The expected corpus is exactly the 51 keys in the canonical catalog.
2. Every file is version 3, approved, active, within its review period, and matched by exactly one approval record.
3. Approved and active are symmetrical: an approved guide cannot be inactive, and an active guide cannot be draft or unapproved.
4. The 34 reference documents remain unchanged, active, PDF-linked, and separately cited.
5. Legacy `seed:*` module summaries remain disabled.
6. User permissions, active role assignments, module activation, and a server-resolved route determine guide access.
7. Client-provided `route_key` and `module_key` can never authorize or boost a guide through an untrusted fallback.
8. Candidate authorization happens before guide content and chunks are hydrated.
9. Maintainer-only sections never enter active retrieval chunks, prompts, traces, or citations.
10. Unauthorized titles, IDs, source paths, chunks, and citation metadata never appear in a response or trace.
11. System-guide citations never call the PDF endpoint or expose a document ID.
12. Production readiness cannot pass while system-guide retrieval is disabled or approval enforcement is disabled.
13. No partial wave is seeded or enabled in production.
14. A failed migration, seed, embedding, evaluation, or readiness gate leaves the application in maintenance mode.

## 4. Workstream A — Finalize the catalog and content contract

### 4.1 Split catalog data from validation logic

Files:

- `config/ai_helper_system_guides.php` — new canonical data file
- `app/Services/AiHelperSystemGuideCatalog.php`
- `app/Services/AiHelperSystemGuideContentValidator.php` — new
- `app/Services/AiHelperSystemGuideApprovalManifest.php`

Changes:

1. Move the 51 guide definitions and trusted route patterns out of the catalog service into `config/ai_helper_system_guides.php`.
2. Keep `AiHelperSystemGuideCatalog` responsible for canonical lookup, route resolution, registry validation, expected counts, and safe citation labels.
3. Move frontmatter and prose validation into `AiHelperSystemGuideContentValidator` so the catalog does not continue growing as content rules become stricter.
4. Validate the following for every final guide:
   - exact catalog key, module, route, module gate, permissions, permission match, roles, and owner;
   - `knowledge_type: system_guide`;
   - `version: 3`;
   - `release_status: approved`;
   - `active: true`;
   - valid reviewed and review-due dates;
   - all 15 required sections in the required order;
   - at least three concrete ordered workflow steps;
   - exact visible labels, fields, statuses, and recovery messages where applicable;
   - backticked frontend and backend source references in the maintainer section;
   - no generic draft wording, placeholders, guessed behavior, secrets, personal data, or unsupported actions.
5. Preserve strict rejection of unknown permissions, roles, modules, routes, duplicate keys, stale reviews, and unrestricted privileged guides.
6. Validate that the manifest contains exactly 51 known keys for the final corpus. Missing, duplicate, unknown, or stale records fail validation.

### 4.2 Strengthen approval binding

Files:

- `database/ai-helper-system-guides/approvals.json`
- `app/Services/AiHelperSystemGuideApprovalManifest.php`
- `tests/Unit/AiHelperSystemGuideApprovalManifestTest.php`

Each final record must contain:

```json
{
  "key": "leave-self-service",
  "version": 3,
  "content_sha256": "<normalized final body hash>",
  "owner": "Human Resources",
  "approval_reference": "<ticket or signed review reference>",
  "approved_by": "<accountable reviewer>",
  "approved_on": "2026-07-17"
}
```

Rules:

- approval metadata must come from the accountable reviewer; code must not invent it;
- approval is recorded only after the rendered final guide and dossier are reviewed;
- any body change invalidates the hash and requires review plus a new manifest hash;
- a version or owner change invalidates the record;
- an approval date cannot be in the future;
- production always keeps approval enforcement enabled.

Add a read-only Artisan command:

```text
php artisan ai-helper:system-guides:audit --json
```

The command should parse all guides, print canonical hashes and validation results, compare the manifest, and exit non-zero on any discrepancy. It must not generate approval identities or mutate the manifest.

## 5. Workstream B — Complete all 51 MVP guides

### 5.1 Per-guide source dossier

Create or complete one dossier for every guide under:

```text
docs/ai-helper-system-guide-reviews/<guide-key>.md
```

Every dossier must record:

1. frontend routes and guards;
2. page/component names and visible navigation labels;
3. form fields, types, required/optional state, defaults, and limits;
4. frontend validation and user-visible error messages;
5. backend routes and HTTP methods;
6. controller, request, policy, middleware, and service enforcement;
7. module gate;
8. permissions and active role-assignment behavior;
9. statuses, transitions, declarations, version/concurrency rules, and next actors;
10. attachment/media rules and authorization;
11. focused backend and frontend tests;
12. discrepancies found and their code or guide resolution;
13. owner decision, approval reference, final version, and final content hash.

The dossier remains maintainer evidence and is never seeded into Ask AI.

### 5.2 Revalidate the 16 version 3 candidates

Files:

- `database/ai-helper-system-guides/ask-ai-usage.md`
- `database/ai-helper-system-guides/dashboard-basics.md`
- `database/ai-helper-system-guides/profile-security.md`
- `database/ai-helper-system-guides/profile-banking.md`
- `database/ai-helper-system-guides/profile-medical.md`
- `database/ai-helper-system-guides/profile-emergency.md`
- `database/ai-helper-system-guides/messages.md`
- `database/ai-helper-system-guides/leave-self-service.md`
- `database/ai-helper-system-guides/leave-management.md`
- `database/ai-helper-system-guides/leave-entitlements.md`
- `database/ai-helper-system-guides/holiday-administration.md`
- `database/ai-helper-system-guides/leave-workflow-rules.md`
- `database/ai-helper-system-guides/overtime-self-service.md`
- `database/ai-helper-system-guides/overtime-management.md`
- `database/ai-helper-system-guides/overtime-rates.md`
- `database/ai-helper-system-guides/overtime-workflow-rules.md`

Tasks:

1. Re-run their source traces against the post-RBAC-remediation code.
2. Replace any stale labels, transition descriptions, owner rules, or error recovery text.
3. Confirm ordinary-user guides do not disclose management actions.
4. Confirm management guides describe active assignment scope and exact next-actor rules.
5. Complete the approval section in each dossier only after final review.

### 5.3 Rewrite the remaining 35 version 2 drafts

#### Finance and payroll — 8 guides

Guide keys:

- `payroll-self-service`
- `payroll-claims`
- `salary-claims-management`
- `payment-actions`
- `salary-assignments`
- `payroll-statutory-rates`
- `payroll-company-profile`
- `payroll-workflow-rules`

Primary source areas:

- frontend `src/views/payroll/**`;
- frontend `src/views/staff/salary-claims-management/**`;
- frontend `src/services/payrollClaims/**`, `payrollClaimsApi.js`, and `salaryAssignmentsApi.js`;
- backend payroll/claim/assignment controllers, request validation, workflow services, models, settings endpoints, notifications, and payment concurrency tests.

Required MVP focus:

- distinguish payslips, expense claims, salary claims, salary assignments, and payment actions;
- exact creation, draft, edit, cancel, correction, resubmission, review, approval, payment, and unpayment rules;
- exact declaration and optimistic-version requirements;
- exact evidence, payment reference/date, statutory-rate, company-profile, and effective-date rules;
- ensure `staff.salary.manage` never implies `staff.salary.pay`.

#### Staff, users, teams, and rosters — 9 guides

Guide keys:

- `staff-directory`
- `staff-records`
- `user-administration`
- `role-assignments`
- `password-session-controls`
- `teams-view`
- `teams-manage`
- `roster-view`
- `roster-manage`

Primary source areas:

- frontend `src/views/staff/**`, `src/views/users/**`, `src/components/users/**`, `src/views/team/**`, and `src/views/roster/**`;
- frontend user, team, roster, image, and assignment API services;
- backend user/staff/team/roster controllers, request validators, assignment authorization, image handling, role assignment, session revocation, and roster publication tests.

Required MVP focus:

- separate redacted directory viewing from staff or user administration;
- exact create, edit, archive/delete, invitation, status, role assignment, password reset, and session revocation behavior;
- team scope and active assignment constraints;
- roster create/edit/conflict/publish behavior and who can see unpublished information.

#### Reports and inspections — 11 guides

Guide keys:

- `reports-navigation`
- `erco-reports`
- `drill-reports`
- `fitness-reports`
- `report-management`
- `inspection-view`
- `inspection-manage`
- `extinguisher-management`
- `inspection-issue-management`
- `inspection-issue-verification`
- `inspection-workflow-settings`

Primary source areas:

- frontend `src/views/report/**`, `src/components/report-workflow/**`, and `src/views/inspection/**`;
- backend report, inspection, issue, equipment, extinguisher, site/location, media, export, session, and workflow controllers/services;
- report/inspection request validation, workflow policy, media authorization, offline/session behavior, PDF/export tests, and inspection type matrices.

Required MVP focus:

- exact report-type routes, fields, drafts, media, submission, review, approval, rejection, correction, export, and scope behavior;
- exact ERCO, drill, and fitness differences;
- inspection type-specific setup and required checks for general, fire extinguisher, fire truck, SCBA, hydraulic, high-angle, and ER auxiliary flows;
- session reuse, idempotency, offline queue, evidence, issue creation/verification, equipment management, and concurrency behavior;
- do not flatten materially different inspection workflows into generic instructions; use explicit subsections within the relevant guide and split the catalog only if the audited content cannot remain safe and understandable under the existing permission boundary.

#### Settings, audit, notifications, and Ask AI administration — 7 guides

Guide keys:

- `module-activation`
- `role-permissions`
- `dashboard-visibility`
- `system-maintenance`
- `workflow-notifications-settings`
- `audit-logs`
- `ask-ai-administration`

Primary source areas:

- frontend `src/views/settings/**`, `src/views/audit/**`, `src/views/notifications/**`, and `src/views/admin/ai-helper-knowledge/**`;
- backend settings, module activation, permission, audit, notification, maintenance, Ask AI knowledge/report controllers and commands;
- role catalog, module catalog, scheduler/queue behavior, authorization, diagnostics, and admin tests.

Required MVP focus:

- distinguish settings permissions from System Administrator-only operations;
- explain locked modules and cascading module effects;
- exact role-permission, dashboard visibility, workflow notification, maintenance, audit filter, and Ask AI corpus administration behavior;
- never expose guide bodies, prompts, user messages, secrets, source paths, or internal storage paths.

### 5.4 Finalize frontmatter

After content and owner review, every guide must be changed atomically to:

```yaml
version: 3
release_status: approved
active: true
reviewed_on: <actual approval date>
review_due_on: <90-day review date>
```

The corresponding approval record must be added in the same final change. There must never be a committed state on the release branch where a guide is active without its matching approval.

## 6. Workstream C — Seeder and database lifecycle

Files:

- `database/seeders/AiHelperSystemGuideSeeder.php`
- `database/seeders/DatabaseSeeder.php`
- `app/Services/AiHelperKnowledgeProcessingService.php`
- `tests/Feature/AiHelperSystemGuideSeederTest.php`

Changes:

1. Preserve the existing parse-and-validate-all behavior before the first database mutation.
2. Refuse the entire final corpus if any of the 51 guides is draft, inactive, missing, duplicated, stale, unapproved, or hash-mismatched.
3. Seed approved entries as shared, active, approved, `source_document_id=null`, and `source_path=seed:system-guide:<key>`.
4. Process user-facing sections only; exclude the maintainer source section and guide-maintenance section from chunks and embeddings.
5. Keep seeding idempotent by key and content hash.
6. Preserve existing chunk IDs when content and embedding configuration are unchanged.
7. Rebuild only changed guides and emit `created`, `updated`, `unchanged`, `activated`, `deactivated`, and `failed` counts.
8. Disable removed or legacy system-guide keys without touching reference-document entries.
9. Audit activation, deactivation, version, and content-hash changes without logging guide bodies.
10. Add `AiHelperSystemGuideSeeder` to `DatabaseSeeder` only in the same final change where all 51 files and approvals are complete.
11. Keep the explicit production seed command in the deployment runbook even after adding it to `DatabaseSeeder`.

Tests must prove:

- exactly 51 active, approved, version 3 entries are created;
- all 51 have active chunks;
- all manifest hashes match;
- a second seed is idempotent;
- one altered guide makes the seed fail before any entry changes;
- one missing approval makes the seed fail before any entry changes;
- maintainer headings produce zero active chunks;
- the 34 reference entries remain byte-for-byte and relationship-equivalent;
- legacy seed entries are disabled.

## 7. Workstream D — Retrieval and authorization completion

Files:

- `app/Services/AiHelperKnowledgeAudienceResolver.php`
- `app/Services/AiHelperKnowledgeRetriever.php`
- `app/Services/AiHelperKnowledgeService.php`
- `app/Services/AiHelperCitationValidator.php`
- `app/Services/AiHelperResponsePipeline.php`
- `tests/Feature/AiHelperSystemGuideRetrievalTest.php`
- `tests/Unit/AiHelperKnowledgeAudienceResolverTest.php`

Changes:

1. Keep one immutable audience context per request using the authenticated user, active role assignments, effective permissions, module states, and server-resolved route.
2. Remove the current ranking fallback to client-provided `route_key` or `module_key` when the trusted route resolver returns no match. Unknown paths receive no route boost.
3. Continue selecting candidate access metadata first and hydrating full content/chunks only after authorization.
4. In the metadata query, require active status for final system guides; processing entries must never be eligible for user retrieval.
5. Keep permission-first checks, explicit any/all behavior, optional role restrictions, module gates, review expiry, catalog matching, and approval matching.
6. Verify the System Administrator override bypasses permission/role matching only; it must not bypass feature enablement, module disablement, inactive status, expiry, catalog mismatch, or approval mismatch.
7. Keep question routing separate:
   - UI/navigation/workflow questions prefer system guides;
   - emergency/policy/procedure/telephone/annex questions prefer references;
   - mixed questions may use both with distinct claims and citations.
8. Ensure traces contain only authorized entry and chunk IDs.
9. Ensure prompts never contain permissions, roles, source paths, filenames, approval metadata, or maintainer sections.
10. Keep system-guide citations non-downloadable and free of `document_id`.

Replace handcrafted retrieval fixtures that set `system_guide_approval_enforced=false`. The main feature suite must seed the real final corpus with approval enforcement enabled and exercise actual catalog metadata.

## 8. Workstream E — Readiness and evaluation must represent the final release

### 8.1 Readiness semantics

Files:

- `app/Console/Commands/CheckAiHelperKnowledgeReadiness.php`
- `app/Services/AiHelperKnowledgeService.php`
- `tests/Feature/AiHelperKnowledgeReadinessCommandTest.php`

Changes:

1. `role_aware_retrieval_ready` must require the complete system-guide corpus; it must not become true merely because system guides are disabled.
2. Add explicit payload fields:
   - `system_guides_runtime_enabled`;
   - `approval_enforcement_enabled`;
   - `approval_manifest_valid`;
   - `approval_manifest_records`;
   - `deployment_state` with `incomplete`, `staged_disabled`, or `production_ready`.
3. `--production` must fail unless:
   - system guides are enabled;
   - approval enforcement is enabled;
   - all 51 guides are present, version 3, active, approved, within review, indexed, and hash-matched;
   - every guide has valid permissions, roles, module gates, route/catalog metadata, and active chunks;
   - maintainer chunk violations are zero;
   - processing, failed, missing embedding, and legacy active counts are zero;
   - the 34 references remain ready and PDF-linked;
   - retrieval v3, embeddings, citation validation, critical-fact validation, and enforced grounding use valid production configuration.
4. Non-production readiness may report a staged-disabled corpus for UAT preparation, but must not label it production ready.
5. Add regression tests proving that disabled guides, disabled approval enforcement, an empty manifest, a stale review, one missing embedding, or one active legacy entry each fails the production gate.

### 8.2 Role-aware evaluator

Files:

- `app/Console/Commands/EvaluateAiHelperKnowledge.php`
- `app/Support/AiHelperKnowledgeEvaluationCases.php`
- `tests/Feature/AiHelperKnowledgeEvaluationCommandTest.php`

The optional simulated-user evaluator proposed in this historical plan was removed to keep deployment focused on the standard retrieval benchmarks. The active evaluator supports only `core`, `coverage`, and `all`; access control remains covered by application authorization tests and manual role-based smoke checks.

## 9. Workstream F — Frontend contract and route checks

Files:

- `vmecc-frontend/src/components/ai-helper/MessageBubble.js`
- `vmecc-frontend/src/components/ai-helper/__tests__/MessageBubble.test.jsx`
- `vmecc-frontend/src/components/ai-helper/routeContext.js`
- `vmecc-frontend/src/components/ai-helper/__tests__/routeContext.test.js`
- `vmecc-frontend/src/routes.js`
- a new focused route-contract test beside `routes.js`

Changes and verification:

1. Retain clickable PDF citations with page fragments for `reference_document`.
2. Retain non-clickable `VMECC System Guide: <title> (v3)` rendering for `system_guide`.
3. Never call the PDF endpoint when `document_id` is null or the source type is `system_guide`.
4. Verify mixed reference/system citations render independently and accessibly.
5. Verify no source path, filename, chunk ID, permissions, roles, approval data, or raw Markdown is displayed.
6. Add a route-contract table covering every catalog route family and assert that each production path used by a guide exists in the frontend route registry.
7. Keep route context a relevance hint only; it must not contain or imply authorization state.

No broad frontend redesign is required for the MVP unless content auditing reveals a mismatch between an actual visible label and the backend-supported workflow. Any such mismatch must be resolved in code or documented as unavailable; it must not be guessed in a guide.

## 10. Test matrix and release gates

### 10.1 Targeted backend gates

```bash
php artisan test tests/Unit/AiHelperSystemGuideCatalogTest.php
php artisan test tests/Unit/AiHelperSystemGuideApprovalManifestTest.php
php artisan test tests/Unit/AiHelperKnowledgeAudienceResolverTest.php
php artisan test tests/Feature/AiHelperSystemGuideSeederTest.php
php artisan test tests/Feature/AiHelperSystemGuideRetrievalTest.php
php artisan test tests/Feature/AiHelperKnowledgeReadinessCommandTest.php
php artisan test tests/Feature/AiHelperSystemGuideEvaluationCommandTest.php
```

Also run the workflow suites used as source-of-truth evidence for leave, overtime, payroll, staff/users, teams/rosters, reports, inspections, settings, notifications, and audit behavior.

### 10.2 Targeted frontend gates

```bash
npm run lint
npx vitest run --environment jsdom src/components/ai-helper src/services src/views/settings src/views/payroll src/views/staff src/views/report src/views/inspection
```

### 10.3 Full local release gate

Use an isolated PostgreSQL database:

```bash
composer install --no-interaction --prefer-dist --no-progress
composer audit
php artisan migrate:fresh --env=testing --force
php artisan db:seed --class=AiHelperReferenceCorpusSeeder --force
php artisan db:seed --class=AiHelperSystemGuideSeeder --force
php artisan test
php artisan route:list
vendor/bin/pint --test <all changed PHP files>

npm ci
npm audit --audit-level=high
npm run lint
npx vitest run --environment jsdom
npm run build -- --mode production
```

Verify the production build contains `.htaccess`, contains only the production API URL, and contains no localhost API URL.

### 10.4 UAT release gate

With system guides enabled and approval enforcement enabled:

```bash
php artisan ai-helper:system-guides:audit --json
php artisan ai-helper:knowledge-readiness --production --json
php artisan ai-helper:evaluate-knowledge --suite=core --json
php artisan ai-helper:evaluate-knowledge --suite=coverage --json
```

Run manual role-based smoke checks using System Administrator, HR, Finance, Operations/management, and ordinary self-service accounts. Confirm both positive access and negative isolation.

## 11. Approval and integration sequence

1. Create the corrective release branch from the current backend and frontend baselines.
2. Complete code safeguards and tests locally without enabling production.
3. Complete all 51 guides and dossiers.
4. Run source-of-truth workflow suites and correct every discrepancy.
5. Render the guides and produce the read-only hash audit.
6. Obtain accountable HR, Finance, Operations, and System Administration review metadata.
7. Add the 51 manifest records and atomically mark all guides version 3, approved, and active.
8. Seed an isolated database and run all targeted and full gates.
9. Run UAT with real role assignments and module states.
10. Record final UAT approval against the exact commit and guide hashes.
11. Update the tracked production runbook with the final commit IDs and exact commands.
12. Merge or squash the complete corrective release to `main` only after all preceding steps are green.
13. Push backend and frontend final commits.
14. Deploy only those final commits.

The final integration must not contain any version 2 guide, draft guide, inactive guide, missing dossier, placeholder approval, bypassed approval test, or skipped system-guide release gate.

## 12. Production deployment sequence

Add a tracked backend runbook at:

```text
docs/AI_HELPER_SYSTEM_GUIDE_PRODUCTION_RUNBOOK.md
```

This is necessary because the workspace-root `DEPLOYMENT.md` is outside both Git repositories and cannot be delivered by a repository pull.

The final backend deployment block must:

1. verify a clean server worktree and expected final commit;
2. take and verify a production database backup;
3. verify PHP and Composer versions;
4. set `AI_HELPER_SYSTEM_GUIDES_ENABLED=true` and `AI_HELPER_SYSTEM_GUIDE_APPROVAL_ENFORCED=true` in the server-only `.env`;
5. enter maintenance mode;
6. install locked production dependencies;
7. run migration status and migrations;
8. seed the 34 references;
9. seed all 51 system guides;
10. drain embedding jobs;
11. rebuild configuration, route, and view caches;
12. restart queue workers;
13. run the guide audit, production readiness, deterministic core, and deterministic coverage gates;
14. reopen traffic only if every command exits successfully;
15. run role-based and citation smoke checks.

Required final readiness values:

```text
ready: true
reference_knowledge_ready: true
system_guides_ready: true
role_aware_retrieval_ready: true
system_guides_runtime_enabled: true
approval_enforcement_enabled: true
reference_knowledge.total: 34
reference_knowledge.active: 34
system_guides.total: 51
system_guides.active: 51
system_guides.approved: 51
system_guides.expected_versions: 51
system_guides.approval_hash_matches: 51
system_guides.processing: 0
system_guides.failed: 0
system_guides.missing_embeddings: 0
system_guides.legacy_active: 0
```

## 13. Rollback

If any deployment gate or smoke check fails:

1. leave the application in maintenance mode;
2. set `AI_HELPER_SYSTEM_GUIDES_ENABLED=false` in the server-only `.env`;
3. run `php artisan optimize:clear` and `php artisan config:cache`;
4. restart queue workers;
5. investigate migration, seeding, embedding, catalog, approval, authorization, or evaluation failures;
6. restore the verified backup if database rollback is required;
7. reopen traffic only after confirming the 34-reference corpus remains ready and system guides are excluded.

Application-code rollback alone is not a database rollback. Never reverse production migrations by resetting the repository.

## 14. Definition of done

The goal is complete only when all boxes are true:

- [ ] All 51 guides have complete source dossiers.
- [ ] All 51 guides describe current supported MVP behavior.
- [ ] All 51 guides are version 3, approved, active, and within review.
- [ ] All 51 exact approval records are present and hash-matched.
- [ ] The final seeder is all-or-nothing and idempotent.
- [ ] `DatabaseSeeder` includes the final system-guide seeder.
- [ ] The 34 PDF-linked references remain unchanged and ready.
- [ ] Unauthorized content is never hydrated, traced, prompted, or cited.
- [ ] The production readiness command fails when either safety flag is false.
- [ ] Authorized and unauthorized cases exist for every guide.
- [ ] Disabled-module and forged-context tests cover every guide.
- [ ] Bahasa Melayu, mixed-language, and injection suites pass.
- [ ] Full backend and frontend suites pass.
- [ ] Production frontend build checks pass.
- [ ] UAT deterministic and live evaluations pass.
- [ ] The tracked server runbook contains the final deployment and rollback commands.
- [ ] Final backend and frontend commits are clean, pushed, and identified in the runbook.
- [ ] Production is enabled only after the final readiness result is fully green.
