# Review dossier: report-management

Status: version 3 final workflow verification.

- Contract: report workspaces; `reports.manage`; module `reports`.
- UI/API evidence: report action hooks/modal, `ReportController`, `ReportingWorkflowService`, workflow tests.
- Validation: remarks max 2,000 and required for reject; current version at least 1.
- Verified transitions: Submitted -> Reviewed -> Approved; Rejected from Submitted or Reviewed; replay is idempotent.
- Security: type-specific module and scope checks still apply to broad management.

- Frontend route evidence: `vmecc-frontend/src/routes.js`.
- Backend route and authorization evidence: `vmecc-backend/routes/api.php`.
## Verified user workflow

- Route registration: `vmecc-frontend/src/routes.js`.
- Visible page, labels, fields, and sequencing: `vmecc-frontend/src/views/report/hooks/useReportWorkflowActions.js`.
- API registration and middleware boundary: `vmecc-backend/routes/api.php`.
- Validation, authorization, or workflow enforcement: `vmecc-backend/app/Services/ReportingWorkflowService.php`.

## Verification coverage

- Focused automated evidence: `vmecc-backend/tests/Feature/ReportApiWorkflowTest.php`.

## Discrepancies

No unresolved guide-to-code discrepancy remains after the final-label and workflow audit.
