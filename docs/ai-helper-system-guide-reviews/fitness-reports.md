# Review dossier: fitness-reports

Status: version 3 final workflow verification.

- Contract: `/report/fitness-test`; `reports.fitness.view`; module `reports.fitness_test`.
- UI/API evidence: Fitness Test form/records, `ReportController`, `FitnessTestReportPayloadService`, workflow tests.
- Verified behavior: separate draft/submit validation and managed workflow; no dedicated server PDF endpoint.
- Security: measurements and records remain within report permission and assignment scope.
- Recovery: reload stale state; correct server validation.

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
