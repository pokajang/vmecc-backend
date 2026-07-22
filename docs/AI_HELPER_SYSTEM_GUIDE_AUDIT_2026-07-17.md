# Ask AI System-Guide Audit

> Historical audit record. Its environment-switch and deployment references are superseded by `AI_HELPER_SYSTEM_GUIDE_PRODUCTION_RUNBOOK.md` and the four-variable environment boundary in `config/ai_helper.php`; do not use this file as a deployment runbook.

Date: 2026-07-17

Scope: role-aware system-guide catalog, Markdown corpus, seeder, authorization, retrieval, citations, readiness, and deployment gate

## Result

Production release is **not approved**. The access-control infrastructure is fail-closed and its focused tests pass. Waves A and B now have 16 source-traced version 3 candidates and individual review dossiers; the other 35 Markdown files remain authoring drafts. All 51 remain explicitly marked `release_status: draft` and `active: false`; seeding stores them as pending, disabled entries and readiness rejects them until every guide is approved.

The isolated verification database has run the access-control migration and the disabled-corpus seeder/idempotency/readiness tests. No production or shared development database was mutated. The existing 34-reference deployment remains the only releasable Ask AI corpus.

## Implementation progress

- WF-RBAC-001 through WF-RBAC-008 are resolved and covered by focused workflow/audit suites.
- Hash-bound approvals, approved/active symmetry, draft-approval rejection, maintainer-chunk exclusion, v3 readiness counts, and idempotent chunk preservation are implemented.
- Wave A candidates: `ask-ai-usage`, `dashboard-basics`, `profile-security`, `profile-banking`, `profile-medical`, `profile-emergency`, and `messages`.
- Wave B candidates: all nine leave and overtime guides in the remediation plan.
- Wave A/B evidence: `docs/ai-helper-system-guide-reviews/*.md` for the 16 candidates.
- Remaining content waves and named module-owner approvals are still required. No approval identity or ticket reference has been invented.

## Findings and corrections

### SG-001 — High — Generic drafts could be promoted as approved guides

The corpus used repeated generic instructions such as “open the stated page” and generic maintainer references instead of audited routes, labels, fields, validation rules, statuses, transitions, and concrete source files. The prior validator checked headings and obvious placeholder words only, so these files appeared complete.

Corrections:

- added required `release_status` metadata;
- marked all current guides `draft` and inactive;
- prevented an active draft from validating;
- made approved content reject known draft boilerplate;
- required approved guides to contain concrete ordered steps and backticked frontend/backend source references;
- made the seeder store drafts as `review_status=pending`, `status=disabled`, with inactive chunks.

### SG-002 — Medium — Readiness could report an unusable corpus as ready

Readiness did not require every entry to have an active chunk and could accept an `active=true` row whose status was `disabled`. System-guide chunk counts also included non-catalog system-guide rows.

Corrections:

- require `status=active` for every ready reference and guide;
- require at least one active indexed chunk per entry;
- count system-guide embeddings only for `seed:system-guide:*` rows;
- expose `indexed` and `status_active` counts in readiness output.

### SG-003 — Medium — Stored metadata did not prove code-controlled ownership

Catalog matching checked permissions, roles, modules, routes, and owner, but did not reject a system-guide row linked to a document, attributed to an uploader, using personal visibility, or using a non-Markdown MIME type.

Correction: catalog matching now requires a null uploader, null document link, shared visibility, and `text/markdown` source MIME in addition to canonical access metadata.

### SG-004 — Low — Wildcard effective permission semantics differed from API authorization

The application authorization service treats an effective `*` permission as satisfying concrete permissions. The guide audience matcher required exact names, creating inconsistent false denials.

Correction: the audience matcher now honors an effective `*` permission while retaining role and module checks.

### SG-005 — Low — Seeder audit actions did not distinguish activation changes

Correction: seed audit events now distinguish seeded, activated, deactivated, and updated guide states. Catalog registry validation also runs before any seeder mutation.

## Release gate

Follow the executable remediation sequence in [`AI_HELPER_SYSTEM_GUIDE_REMEDIATION_PLAN_2026-07-17.md`](AI_HELPER_SYSTEM_GUIDE_REMEDIATION_PLAN_2026-07-17.md). It defines the per-guide source dossiers, workflow-blocker order, owner/hash approvals, six content waves, test matrix, UAT, production, and rollback gates required to seed the corpus as final.

Each guide must be traced against its current frontend route/component, visible form labels, backend route/controller/request validation, module middleware, permission middleware, workflow service, and focused tests. Replace generic prose with exact behavior, record module-owner approval, set `release_status: approved` and `active: true`, then run:

```bash
php artisan db:seed --class=AiHelperSystemGuideSeeder --force
php artisan queue:work database --stop-when-empty --tries=3 --timeout=900
php artisan ai-helper:knowledge-readiness --production --json
```

Do not enable `AI_HELPER_SYSTEM_GUIDES_ENABLED` while any guide remains a draft or readiness reports `system_guides_ready: false`.
