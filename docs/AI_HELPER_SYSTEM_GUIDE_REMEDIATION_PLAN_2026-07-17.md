# Final System-Guide Remediation Plan

> Superseded on 2026-07-17 by `AI_HELPER_SYSTEM_GUIDE_LAYMAN_MVP_CONTRACT_2026-07-17.md`. Approval-manifest proposals below are historical and must not be used for deployment.

Date: 2026-07-17

Target: 51 final, code-controlled, role-aware VMECC system guides

Current state: 51 inactive drafts; system-guide retrieval disabled

## 1. Outcome

Complete this plan when all 51 guides are:

- traced to the current frontend, API, validation, authorization, module, workflow, attachment, and test implementations;
- written with exact user-visible routes, labels, fields, limits, statuses, transitions, recovery steps, and next actors;
- split on real permission boundaries, with no privileged instructions in self-service guides;
- reviewed by the responsible module owner against an immutable content hash;
- marked `release_status: approved`, `active: true`, and version `3`;
- seeded idempotently as shared, approved, active `system_guide` entries;
- chunked and embedded without exposing maintainer-only sections;
- verified across the required role matrix, route contexts, disabled modules, languages, and injection attempts;
- accepted by UAT and `ai-helper:knowledge-readiness --production --json` before production traffic opens.

The 34 PDF-linked reference documents remain a separate corpus and must not change during this work.

## 2. Non-negotiable release rules

No guide may move from draft to approved based only on prose review. Approval requires a completed source dossier, green automated cases, module-owner sign-off, and an approval record bound to the exact Markdown hash.

A guide is final only when all of these are true:

1. Its catalog permission, role, module gate, route key, owner, and title match the intended workflow.
2. Every described control exists in the current UI.
3. Every described field and limit matches backend validation.
4. Every status and transition matches the authoritative workflow service.
5. Every next actor matches active role-assignment and scope rules.
6. Known workflow defects affecting the guide are resolved or the guide remains inactive.
7. Its source dossier and approval hash match the final file.
8. Authorized retrieval tests pass and unauthorized retrieval produces no guide ID, title, chunk, trace, or citation.
9. The seeder creates active chunks and embeddings for the approved version.
10. Readiness reports all 51 as active, approved, indexed, access-controlled, in review, catalog-valid, and embedded.

## 3. Remediation dependencies

Resolve the workflow audit findings before approving affected guides. Do not document insecure behavior as an accepted workflow.

| Workflow finding | Required remediation | Guides blocked |
|---|---|---|
| WF-RBAC-001 | System Administrator may override role ownership, but must still satisfy status, stage, declaration, and concurrency rules. | `leave-management`, `overtime-management`, `salary-claims-management` |
| WF-RBAC-002 | Every configured payroll stage role must have route permission, or settings must reject unreachable roles. | `salary-claims-management`, `payroll-workflow-rules` |
| WF-RBAC-003 | Define and enforce stage-owner or explicit override permission for payroll rejection and cancellation. | `salary-claims-management` |
| WF-RBAC-004 | Add payroll workflow optimistic locking with an expected version and transactional conditional update. | `salary-claims-management`, `payment-actions` |
| WF-RBAC-005 | Add safe non-empty leave workflow defaults and reject unreachable configured stages. | `leave-management`, `leave-workflow-rules` |
| WF-RBAC-006 | Preserve report type/UID in notification detail links. | `reports-navigation`, `report-management`, `erco-reports`, `drill-reports`, `fitness-reports`, inspection guides |
| WF-RBAC-007 | Adopt one documented System Administrator workflow policy across leave, overtime, payroll, and reporting. | All management and report-workflow guides |
| WF-RBAC-008 | Decide and enforce leave history retention before claiming audit-history behavior. | `leave-management` |

Re-run the green workflow suites and the intentional audit probes after remediation. A formerly red probe becomes part of the normal regression suite once fixed.

## 4. Technical safeguards to complete before content approval

### 4.1 Hash-bound owner approvals

Add a code-controlled approval manifest, for example:

```text
database/ai-helper-system-guides/approvals.json
```

Each record should contain:

```json
{
  "key": "leave-self-service",
  "version": 3,
  "content_sha256": "<hash of normalized final Markdown body>",
  "owner": "Human Resources",
  "approval_reference": "<review ticket or signed checklist reference>",
  "approved_by": "<accountable reviewer>",
  "approved_on": "2026-07-17"
}
```

Seeder and readiness requirements:

- an approved/active guide must have exactly one manifest record;
- key, version, owner, and normalized body hash must match;
- changing body content invalidates the old approval automatically;
- draft guides must not have a current approval record;
- duplicate, missing, stale, or unknown approvals fail before any guide mutation;
- approval metadata may appear in administrator diagnostics, never in user citations or prompts.

### 4.2 Keep maintainer material out of the model context

The required maintainer sections contain repository paths and review metadata. Preserve them in code-controlled Markdown, but do not expose them as user guidance.

Update structured processing so these sections receive a non-retrievable content type or are excluded from active chunks:

- `Source-of-truth code references for maintainers`
- `Guide maintenance`

Add tests proving repository paths, internal class names, approval references, permission arrays, and source filenames do not enter prompt guidance or response sources.

### 4.3 Strengthen final-content validation

For `release_status: approved`, validate all of the following:

- at least three concrete ordered steps with current visible labels;
- concrete frontend and backend source references in the maintainer section;
- no generic draft phrases;
- no unqualified words such as “typically”, “usually”, “if available”, or “depending on configuration” where the code has an exact rule;
- all route paths mentioned in the navigation section exist in the frontend route registry;
- no permission, role, module, or route key outside the server-owned registries;
- no secret values, sample personal data, credentials, tokens, or production identifiers;
- version `3`, current reviewed date, review due date, owner, and a matching approval manifest record;
- approved implies active; draft implies inactive.

### 4.4 Seeder and readiness completion

- Keep full-corpus validation before mutation.
- Keep reference entries outside all system-guide retirement/update queries.
- Ensure an unchanged approved guide does not rebuild chunks or request embeddings unnecessarily.
- Rebuild only when body hash, structured metadata, version, or embedding model changes.
- Report per-guide results: unchanged, created, updated, activated, deactivated, failed.
- Add the system-guide seeder back to `DatabaseSeeder` only after all 51 approval records exist and all release tests pass.
- Continue running the system-guide seeder explicitly in production deployments.

Readiness must additionally report:

- `approval_manifest_valid`
- `approval_hash_matches`
- `guides_with_active_chunks`
- `maintainer_chunks_excluded`
- `expected_versions`

## 5. Source dossier required for every guide

Create one review dossier per guide under a non-runtime documentation directory such as:

```text
docs/ai-helper-system-guide-reviews/<guide-key>.md
```

Each dossier must record:

1. Frontend route and route guard.
2. Page/component and the visible control labels.
3. API endpoints and HTTP methods.
4. Controller and request validator.
5. Permission and scope middleware.
6. Module activation key and dependencies.
7. Model fields and backend validation rules.
8. Workflow service, valid statuses, transitions, declarations, version checks, and next actors.
9. Attachment endpoint, MIME types, sizes, counts, ownership, and download authorization.
10. Exact user-facing error responses and recovery behavior.
11. Existing automated tests and missing tests added during remediation.
12. Discrepancies found and the code or documentation resolution.
13. Owner review decision, approval reference, final version, and content hash.

The dossier is evidence for maintainers; it is not seeded into Ask AI.

## 6. Content workstreams

### Wave A — Global, profile, dashboard, and messages (7 guides)

Owner review: System Administration, with Human Resources for sensitive profile fields.

| Guide | Required final audit focus |
|---|---|
| `ask-ai-usage` | Panel entry points, conversation behavior, supported/unsupported requests, citations, reporting, privacy, and explicit no-action behavior. |
| `dashboard-basics` | Dashboard route, cards visible under each dashboard permission/module, action queue, loading/empty/error states, and navigation targets. |
| `profile-security` | `/profile` and `/profile/security`, editable identity/security fields, password validation, profile image rules, sessions, and confirmation behavior. |
| `profile-banking` | Exact self-service banking fields and validation; no salary, payment, or other-user administration. |
| `profile-medical` | Exact medical fields, optional/required behavior, sensitive-data handling, and who may see/edit them. |
| `profile-emergency` | Emergency-contact fields, formats, validation, privacy, and update confirmation. |
| `messages` | Contacts, threads, send/read/delete behavior, attachment types/limits, deletion scope, empty states, and recovery. |

Minimum source trace:

- `vmecc-frontend/src/routes.js`
- `vmecc-frontend/src/components/ai-helper/`
- profile, dashboard, and message views/services under `vmecc-frontend/src/`
- `vmecc-backend/routes/api.php`
- `AuthController`, dashboard controllers/services, message controllers, attachment controllers, requests, policies, and focused tests

### Wave B — Leave and overtime (9 guides)

Owner review: Human Resources. Workflow security findings WF-RBAC-001, WF-RBAC-005, WF-RBAC-007, and WF-RBAC-008 must be resolved where applicable.

| Guide | Required final audit focus |
|---|---|
| `leave-self-service` | Apply/edit/cancel/resubmit paths; leave type, dates, computed days, balances, roster impact, evidence, correction, and applicant-visible states. |
| `leave-management` | List/detail filters, review/recommend/approve/reject/correction/cancel actions, declaration/remarks, stage role, expected version, history, and next actor. |
| `leave-entitlements` | Assignment create/update/delete fields, effective periods, overlap/balance validation, history, and effect on requests. |
| `holiday-administration` | Batch/edit/delete behavior, date/name/state fields, duplicates, affected leave calculations, and recovery. |
| `leave-workflow-rules` | Safe defaults, stage roles, optional recommendation, validation of reachable roles, change timing, and in-flight record behavior. |
| `overtime-self-service` | Eligibility, date classification, time/duration/rate presentation, draft/edit/cancel/resubmit, evidence, and applicant-visible states. |
| `overtime-management` | Team scope, review/recommend/approve/reject/correction/cancel, declarations, expected version, history, and next actor. |
| `overtime-rates` | Exact rate fields, effective behavior, permission boundary, validation, and impact timing. |
| `overtime-workflow-rules` | Stage roles, optional recommendation, reachable-role validation, scope, change timing, and in-flight snapshots. |

Minimum source trace:

- `/leave`, `/leave/new`, `/staff/leave-management/*`
- `/overtime`, `/overtime/new`, `/staff/overtime-management/*`
- leave/overtime frontend views, shared workflow contracts, and API services
- leave/overtime controllers, request validators, workflow services, scope services, models, notifications, action queue, and RBAC suites

### Wave C — Payroll, claims, payments, and salary configuration (8 guides)

Owner review: Finance, with Human Resources for assignments. WF-RBAC-001 through WF-RBAC-004 and WF-RBAC-007 are release blockers where applicable.

| Guide | Required final audit focus |
|---|---|
| `payroll-self-service` | Payslip list/download, visible pay periods and amounts, download authorization, empty/error states, and no claim-management instructions. |
| `payroll-claims` | Expense versus salary claim creation, drafts, fields, evidence, edit/cancel/resubmit rules, validation, and user-visible states. |
| `salary-claims-management` | Check/review/approve/reject/cancel stages, exact stage-owner policy, declarations, expected version, history, and next actor. |
| `payment-actions` | Approved-only payment eligibility, payment reference/date fields, single/bulk mark-paid and unmark-paid, audit history, and concurrency behavior. |
| `salary-assignments` | Create/edit/view/history/draft routes, salary/effective fields, overlaps, validation, and deletion rules. |
| `payroll-statutory-rates` | Exact statutory fields, number/range validation, effective behavior, and the `settings.manage|staff.salary.manage` boundary. |
| `payroll-company-profile` | Exact legal/company fields, validation, document output effects, and the correct module gate. |
| `payroll-workflow-rules` | Stage roles, reachability validation, default rules, in-flight behavior, and separation from payment permissions. |

Minimum source trace:

- `/payroll/*` and `/staff/salary-claims/*`
- payroll/claim frontend views, form schemas, shared workflow contracts, and API services
- payroll claim/assignment controllers and validators, `PayrollClaimWorkflowService`, settings endpoints, payment endpoints, models, notifications, and RBAC tests

### Wave D — Staff, users, teams, and rosters (9 guides)

Owner review: Human Resources for staff; System Administration for accounts/roles; Operations for teams/rosters.

| Guide | Required final audit focus |
|---|---|
| `staff-directory` | Search/filter/list/profile navigation and the strict `staff.view` data boundary. |
| `staff-records` | Editable staff fields, validation, images/files, create/update/delete or restore behavior, and separation from account/role controls. |
| `user-administration` | Account creation, status/lock/unlock/delete/restore controls, fields, validation, audit effects, and inaccessible staff-only data. |
| `role-assignments` | Add/replace/update/delete assignments, role, scope, team, dates, primary role, overlap/reachability validation, and effect timing. |
| `password-session-controls` | Reset link, lock/unlock, session list/revoke/revoke-all, confirmations, and what administrators cannot view. |
| `teams-view` | List/detail navigation and assignment-scoped visibility. |
| `teams-manage` | Create/edit/delete, member options, image rules, scoped updates, lead/member validation, and impact on active workflows. |
| `roster-view` | Overview/schedule/shift information visible with `teams.view`; no publish/edit instructions. |
| `roster-manage` | Create/edit/publish flow, shifts, conflicts, team scope, publish confirmation, and post-publish behavior. |

Minimum source trace:

- `/admin/users/*`, `/staff/*`, `/team/details/*`, `/roster/*`
- user/staff/team/roster views and API services
- user management, team, roster, assignment authorization, requests, image handling, module gates, and tests

### Wave E — Reports and inspections (11 guides)

Owner review: Operations. Resolve notification routing and administrator-policy inconsistencies before final approval.

| Guide | Required final audit focus |
|---|---|
| `reports-navigation` | Report type selection, list/detail/new routes, permission-filtered visibility, drafts, search/filter, and exact navigation. |
| `erco-reports` | ERCO-specific sections/fields, media, drafts, submit/review/approve/reject, self-action policy, versioning, PDF export, and next actor. |
| `drill-reports` | Drill-specific fields and UI labels mapped to canonical workflow actions, media, drafts, statuses, versioning, export, and next actor. |
| `fitness-reports` | Fitness-test-specific participant/result fields, validation, drafts, submit/review/approve/reject, versioning, and next actor. |
| `report-management` | Shared draft/media/submission/review/approval/rejection behavior, scope, self-action policy, concurrency, notification links, and export eligibility. |
| `inspection-view` | Inspection list/detail/checklist summary, duty context, permission boundary, issue/history visibility, and no management actions. |
| `inspection-manage` | New/edit/session flows for each supported inspection type, sections, findings, media, duty confirmation, submit/review/approve/reject, scope, and versioning. |
| `extinguisher-management` | Catalog/batch/QR lookup, fields, coverage, out-of-service/return/retire/restore/delete states, history, exception export, and confirmations. |
| `inspection-issue-management` | Issue list/detail/update/assign/start/resolve/reopen/cancel fields, allowed transitions, ownership, evidence, and errors. |
| `inspection-issue-verification` | Verification prerequisites, verifier permission, evidence/remarks, valid source states, rejection/reopen consequences, and audit attribution. |
| `inspection-workflow-settings` | Reporting and inspection workflow roles, duty/self-action/distinct-actor policies, reachability, in-flight snapshot behavior, and validation. |

Inspection content must be split internally by fire extinguisher, fire truck, SCBA, high-angle, location/catalog, and live-session workflows where their fields or statuses differ. If one guide cannot remain precise without mixing boundaries, add catalog guides and adjust the expected count instead of compressing incompatible workflows.

Minimum source trace:

- `/report/:reportType/*`, `/inspection/*`, `/report/inspection/*`, `/reporting-settings/*`
- report/inspection views, form helpers, schemas, workflow contracts, media and notification services
- report/inspection controllers, policies, requests, workflow services, catalogs, issue/session controllers, PDF/export controllers, models, and tests

### Wave F — Settings, audit, notifications, and Ask AI administration (7 guides)

Owner review: System Administration, with Operations/HR/Finance confirmation for workflow settings.

| Guide | Required final audit focus |
|---|---|
| `module-activation` | Module tree, locked keys, dependencies, configured versus effective state, save behavior, disabled-feature UX, and cache/runtime effects. |
| `role-permissions` | Role selection, permission groups, wildcard/system administrator behavior, save validation, effect on active assignments, and audit effects. |
| `dashboard-visibility` | Dashboard card permissions/modules, configuration controls, defaults, and user-visible effects. |
| `system-maintenance` | Enable/disable controls, allowed message/fields, exempt access, request behavior, recovery, and deployment interaction. |
| `workflow-notifications-settings` | Notification pages/settings, read/dismiss behavior, deep links, workflow-specific channels, and module/permission boundaries. |
| `audit-logs` | Filters, fields, pagination, immutable/read-only behavior, retention expectations, and sensitive metadata handling. |
| `ask-ai-administration` | Diagnostics, knowledge review, reports moderation, code-controlled guide restrictions, reference uploads, safe metadata, reindex/readiness/evaluation operations, and no raw system-guide download. |

Minimum source trace:

- `/settings/*`, `/reporting-settings/*`, `/notifications/*`, `/admin/audit`, `/admin/ai-helper-*`
- settings/admin/notification/Ask AI views and services
- settings/module/role/audit/notification/Ask AI controllers, authorization services, requests, commands, jobs, diagnostics, and tests

## 7. Authoring procedure for each guide

For each guide, use this exact sequence:

1. Assign an author and owner reviewer.
2. Complete the source dossier before editing the guide.
3. Run or add focused tests for the workflow’s positive and negative cases.
4. Record code/UI discrepancies; fix the implementation first when behavior is unsafe or inconsistent.
5. Rewrite all 15 required guide sections using verified facts only.
6. Use human-readable navigation names and include route paths only where they help the user.
7. Put internal code paths only in the maintainer section.
8. Run catalog/content validation.
9. Run the guide’s authorized and unauthorized retrieval cases.
10. Obtain owner review against the rendered guide and source dossier.
11. Create the hash-bound approval manifest record.
12. Set `version: 3`, `release_status: approved`, `active: true`, actual review dates, and a 90-day review due date.
13. Reseed locally and confirm the guide is active, indexed, embedded, and retrievable only by the intended audience.
14. Re-run the cross-guide leakage suite before merging the wave.

## 8. Automated verification plan

### Unit coverage

- Frontmatter types, required keys, release status, approval manifest, content hash, review dates, and duplicate detection.
- Route paths referenced by approved guides exist in the route registry snapshot.
- Permission any/all, wildcard permission, role restriction, System Administrator policy, module gate, and review expiry.
- Code-controlled invariants: null uploader/document, shared visibility, Markdown MIME, canonical source path.
- Maintainer sections never become active retrieval chunks.
- Citation payloads contain no source path, filename, chunk ID, permissions, roles, approval data, or raw body.
- Reference and system prompt blocks remain separate.

### Per-guide retrieval coverage

Add at least these cases for every guide:

1. Authorized role/permission retrieves the guide for a direct question.
2. A user lacking the permission does not retrieve it.
3. A user with an adjacent permission does not retrieve it.
4. Disabled module rejects it.
5. Forged route/module page context cannot grant it.
6. Exact trusted route improves ranking only after authorization.
7. Unauthorized title/content/ID never appears in sources or trace.
8. English, Bahasa Melayu, and mixed-language usage questions retrieve the same authorized boundary.
9. Prompt injection requesting hidden administrator steps returns no hidden source.
10. A policy/emergency phrasing does not use the guide as policy evidence.

### Role matrix

Run cross-module scenarios for:

- System Administrator
- Admin
- Human Resource
- Finance
- Contract Manager
- Incident Commander
- Assistant Incident Commander
- Tactical Response Team
- Client Contract Manager
- Representative
- basic self-service user

For scoped roles, include one in-scope and one out-of-scope team. Generic guide retrieval may pass from any active scope, but all record APIs must still reject out-of-scope access.

### End-to-end source rendering

- Reference PDF citations remain clickable with page fragments.
- System-guide citations remain non-clickable and show `VMECC System Guide`, title, and v3.
- Mixed answers retain both source types without cross-claiming.
- Null document IDs never trigger the PDF endpoint.
- Knowledge list remains PDF-document-only.
- Admin diagnostics show safe guide/approval status but no content.

## 9. Wave completion gate

A wave can merge only when:

- every guide in the wave has a completed dossier;
- every blocking workflow finding is fixed and tested;
- every guide passes final-content validation;
- approval hashes match;
- authorized/unauthorized cases pass for every guide;
- no guide outside the wave regresses;
- module owner signs the wave checklist;
- the feature flag remains false outside the controlled development/UAT environment.

Do not activate a partial wave in production. Development may seed approved waves for testing, but the production readiness expected count remains 51.

## 10. Final development seed gate

After all six waves are complete:

```bash
php artisan migrate
php artisan db:seed --class=AiHelperReferenceCorpusSeeder --force
php artisan db:seed --class=AiHelperSystemGuideSeeder --force
php artisan queue:work database --stop-when-empty --tries=3 --timeout=900
php artisan ai-helper:knowledge-readiness --json
php artisan ai-helper:evaluate-knowledge --suite=core
php artisan ai-helper:evaluate-knowledge --suite=coverage
```

Expected deterministic result:

- 34 reference entries active, approved, PDF-linked, indexed, and embedded;
- 51 system guides active, approved, hash-approved, in review, indexed, and embedded;
- zero active legacy seed entries;
- zero unauthorized source IDs or leaked titles in evaluation output;
- `reference_knowledge_ready: true`;
- `system_guides_ready: true`;
- `role_aware_retrieval_ready: true`.

Only then re-add `AiHelperSystemGuideSeeder::class` to the normal `DatabaseSeeder` sequence.

## 11. UAT plan

1. Back up the UAT database.
2. Deploy migration, catalog, approval manifest, final guides, seeder, and tests.
3. Seed references and system guides with the feature disabled.
4. Drain embeddings and run readiness.
5. Enable the feature in UAT and rebuild configuration cache.
6. Run scripted role-matrix scenarios.
7. Have HR validate leave/overtime/staff guides.
8. Have Finance validate payroll/payment guides.
9. Have Operations validate reports/inspection/team/roster guides.
10. Have System Administration validate settings/audit/Ask AI guides.
11. Run live model evaluation for English, Bahasa Melayu, mixed language, route context, disabled modules, and injection attempts.
12. Review diagnostics for rejected answers, missing citations, rerank fallbacks, verifier failures, unauthorized-source count, and latency.
13. Record final UAT approval against the deployed commit and guide hashes.

Any content change after UAT invalidates its approval hash and requires reseeding plus affected tests and owner review.

## 12. Production rollout

Use the guarded block in the root `DEPLOYMENT.md` only after UAT approval.

Required order:

1. Confirm the approved commit and all 51 hashes.
2. Take a production database backup.
3. Keep the application’s cached configuration on the disabled flag while pulling code and installing dependencies.
4. Enter maintenance mode.
5. Run migrations.
6. Seed the 34 references and 51 approved system guides.
7. Drain embeddings.
8. Rebuild caches with `AI_HELPER_SYSTEM_GUIDES_ENABLED=true` while still in maintenance mode.
9. Run production readiness and deterministic evaluation.
10. Open traffic only if every gate succeeds.
11. Run role-based smoke checks and citation rendering checks.
12. Monitor denials, rejected answers, missing evidence, embedding failures, latency, and source-type distribution.

## 13. Rollback

If any production gate or smoke check fails:

```bash
php artisan down --retry=60
# Set AI_HELPER_SYSTEM_GUIDES_ENABLED=false in the server-only .env.
php artisan optimize:clear
php artisan config:cache
php artisan queue:restart
php artisan up
```

Do not delete guide rows during rollback. Disabled retrieval must immediately return the system to the unchanged 34-reference corpus. Preserve the database backup and failed readiness/evaluation output for diagnosis, without logging prompts, user messages, or guide bodies.

## 14. Completion checklist

- [ ] WF-RBAC-001 through WF-RBAC-005 resolved and formerly red probes promoted to regression coverage.
- [ ] WF-RBAC-006 and WF-RBAC-007 resolved for reports and cross-family consistency.
- [ ] Leave history policy for WF-RBAC-008 decided and documented.
- [ ] Approval manifest and hash validation implemented.
- [ ] Maintainer sections excluded from retrieval chunks.
- [ ] Final-content validator strengthened.
- [ ] All 51 source dossiers completed.
- [ ] All 51 guides rewritten to exact workflows.
- [ ] All 51 guides set to version 3, approved, active, and within review.
- [ ] HR, Finance, Operations, and System Administration approvals recorded.
- [ ] Per-guide authorized/unauthorized/disabled/forged-route/language/injection tests green.
- [ ] Full role matrix green, including scoped negative cases.
- [ ] Reference and system citation rendering green.
- [ ] 34-reference corpus unchanged and ready.
- [ ] 51-guide corpus seeded, indexed, embedded, and ready.
- [ ] Deterministic and live UAT evaluations green with zero unauthorized sources.
- [ ] Production readiness green before traffic opens.
- [ ] Rollback verified with reference retrieval remaining operational.
