# Review dossier: inspection-issue-management

Status: version 3 final workflow verification.

- Contract: inspection issue queue; `reports.inspection.issues.manage`; module `reports.inspection`.
- UI/API evidence: issue API client, `InspectionFireExtinguisherIssueController`, workflow/sync services and tests.
- Validation: severity enum, metadata limits, current lock version, required corrective action/notes, max 10 photos.
- Verified workflow: Open -> In progress -> Pending verification; reopen/cancel rules documented.
- Security: retired assets block reopen and invalid transitions fail closed.

- Frontend route evidence: `vmecc-frontend/src/routes.js`.
- Backend route and authorization evidence: `vmecc-backend/routes/api.php`.
## Verified user workflow

- Route registration: `vmecc-frontend/src/routes.js`.
- Visible page, labels, fields, and sequencing: `vmecc-frontend/src/views/inspection/records/FireExtinguisherManagementPanel.js`.
- API registration and middleware boundary: `vmecc-backend/routes/api.php`.
- Validation, authorization, or workflow enforcement: `vmecc-backend/app/Http/Controllers/InspectionFireExtinguisherIssueController.php`.

## Verification coverage

- Focused automated evidence: `vmecc-backend/tests/Feature/InspectionFireExtinguisherLifecycleIssueTest.php`.

## Discrepancies

No unresolved guide-to-code discrepancy remains after the final-label and workflow audit.
