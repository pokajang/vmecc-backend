# Review dossier: inspection-issue-verification

Status: version 3 final workflow verification.

- Contract: `/inspection` > All Extinguishers > Managed issues; `reports.inspection.issues.verify`; module `reports.inspection`.
- UI/API evidence: issue verification UI/API, controller, workflow service and feature tests.
- Validation: required verification note max 10,000 and exact integer lock version.
- Verified workflow: only pending verification -> closed through Verify and close; later reopen requires management permission and reason.
- Security: guide requires independent physical verification outside Ask AI.

- Frontend route evidence: `vmecc-frontend/src/routes.js`.
- Backend route and authorization evidence: `vmecc-backend/routes/api.php`.
## Verified user workflow

- Route registration: `vmecc-frontend/src/routes.js`.
- Visible page, labels, fields, and sequencing: `vmecc-frontend/src/views/inspection/records/FireExtinguisherManagementPanel.js`.
- API registration and middleware boundary: `vmecc-backend/routes/api.php`.
- Validation, authorization, or workflow enforcement: `vmecc-backend/app/Services/InspectionFireExtinguishers/FireExtinguisherIssueWorkflowService.php`.

## Verification coverage

- Focused automated evidence: `vmecc-backend/tests/Feature/InspectionFireExtinguisherLifecycleIssueTest.php`.

## Discrepancies

The prior draft invented an Issue Verification page. The final guide now follows All Extinguishers > Managed issues > Verify and close.
