# Review dossier: drill-reports

Status: version 3 final workflow verification.

- Contract: `/report/drill`; `reports.drill.view`; module `reports.drill`.
- UI/API evidence: Drill form/records, `ReportController`, `DrillReportPayloadService`, PDF and workflow tests.
- Verified behavior: draft and strict submit validation; versioned review/approve/reject; throttled PDF.
- Security: module permission, role, scope, state, and version remain server checked.
- Recovery: reload 409; correct exact 422 fields.

- Frontend route evidence: `vmecc-frontend/src/routes.js`.
- Backend route and authorization evidence: `vmecc-backend/routes/api.php`.
## Verified user workflow

- Route registration: `vmecc-frontend/src/routes.js`.
- Visible page, labels, fields, and sequencing: `vmecc-frontend/src/views/report/Reports.js`.
- API registration and middleware boundary: `vmecc-backend/routes/api.php`.
- Validation, authorization, or workflow enforcement: `vmecc-backend/app/Http/Controllers/ReportController.php`.

## Verification coverage

- Focused automated evidence: `vmecc-backend/tests/Feature/ReportApiWorkflowTest.php`.

## Discrepancies

No unresolved guide-to-code discrepancy remains after the final-label and workflow audit.
