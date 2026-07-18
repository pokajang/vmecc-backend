# Review dossier: ask-ai-administration

Status: version 3 final workflow verification.

- Contract: `/admin/ai-helper-knowledge` and `/admin/ai-helper-reports`; System Administrator plus `*`.
- UI/API evidence: admin knowledge/reports pages, `AiHelperController`, requests, lifecycle/processing services and tests.
- Validation: rejection note/admin note max 2,000; lists max 50 rows; PDF default 10,240 KB; Markdown default 1,024 KB.
- Verified behavior: user knowledge lifecycle and report triage are distinct from hash-controlled system-guide release.
- Security: admin pages do not authorize forged lifecycle changes or raw system-guide overwrite.

- Frontend route evidence: `vmecc-frontend/src/routes.js`.
- Backend route and authorization evidence: `vmecc-backend/routes/api.php`.
## Verified user workflow

- Route registration: `vmecc-frontend/src/routes.js`.
- Visible page, labels, fields, and sequencing: `vmecc-frontend/src/views/admin/AiHelperKnowledge.js`.
- API registration and middleware boundary: `vmecc-backend/routes/api.php`.
- Validation, authorization, or workflow enforcement: `vmecc-backend/app/Http/Controllers/AiHelperController.php`.

## Verification coverage

- Focused automated evidence: `vmecc-backend/tests/Feature/AiHelperDocumentApiTest.php`.

## Discrepancies

No unresolved guide-to-code discrepancy remains after the final-label and workflow audit.
