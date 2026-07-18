# Review dossier: inspection-manage

Status: version 3 final workflow verification.

- Contract: `/inspection/new`; `reports.inspection.conduct` or `reports.manage`; module `reports.inspection`.
- UI/API evidence: inspection module/form runtime, `ReportController`, payload/policy/workflow services, inspection tests.
- Verified behavior: duty confirmation, typed forms, drafts, offline recovery, strict submission, and versioned workflow actions.
- Discrepancy resolved: conduct-only inspectors were added to the guide catalog permission-any contract.
- Security: route context never bypasses payload, duty, role, scope, or self-action policy.

- Frontend route evidence: `vmecc-frontend/src/routes.js`.
- Backend route and authorization evidence: `vmecc-backend/routes/api.php`.
## Verified user workflow

- Route registration: `vmecc-frontend/src/routes.js`.
- Visible page, labels, fields, and sequencing: `vmecc-frontend/src/views/inspection/app/InspectionModule.js`.
- API registration and middleware boundary: `vmecc-backend/routes/api.php`.
- Validation, authorization, or workflow enforcement: `vmecc-backend/app/Http/Controllers/ReportController.php`.

## Verification coverage

- Focused automated evidence: `vmecc-backend/tests/Feature/InspectionSessionApiTest.php`.

## Discrepancies

No unresolved guide-to-code discrepancy remains after the final-label and workflow audit.
