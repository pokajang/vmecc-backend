# Review dossier: erco-reports

Status: version 3 final workflow verification.

- Contract: `/report/erco`; `reports.erco.view`; module `reports.erco`.
- UI/API evidence: ERCO form/records, `ReportController`, `ErcoReportPayloadService`, PDF and workflow tests.
- Verified behavior: separate draft/submit validation; versioned review/approve/reject; throttled PDF.
- Security: managed workflow permission and scoped actor path are server enforced.
- Recovery: reload stale version and correct the named ERCO section.

- Frontend route evidence: `vmecc-frontend/src/routes.js`.
- Backend route and authorization evidence: `vmecc-backend/routes/api.php`.
## Verified user workflow

- Route registration: `vmecc-frontend/src/routes.js`.
- Visible page, labels, fields, and sequencing: `vmecc-frontend/src/views/report/erco/ErcoForm.js`.
- API registration and middleware boundary: `vmecc-backend/routes/api.php`.
- Validation, authorization, or workflow enforcement: `vmecc-backend/app/Http/Controllers/ReportController.php`.

## Verification coverage

- Focused automated evidence: `vmecc-backend/tests/Feature/ReportApiWorkflowTest.php`.

## Discrepancies

No unresolved guide-to-code discrepancy remains after the final-label and workflow audit.
