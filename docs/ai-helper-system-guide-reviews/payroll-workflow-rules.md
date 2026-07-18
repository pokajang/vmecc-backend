# Review dossier: payroll-workflow-rules

Status: version 3 final workflow verification.

- Contract: payroll configuration; `settings.manage`; module `payroll.workflow_rules`.
- UI/API evidence: payroll workflow settings; `SettingsController`; `SalaryWorkflowService`; workflow-role tests.
- Validation: check, review, and approve roles must exist and be eligible for salary management.
- Verified workflow: configured stages are check -> review -> approve, with Approved, Rejected, and Cancelled terminal outcomes.
- Security: changing rules does not bypass actor distinctness, scope, state, or version checks.

- Frontend route evidence: `vmecc-frontend/src/routes.js`.
- Backend route and authorization evidence: `vmecc-backend/routes/api.php`.
## Verified user workflow

- Route registration: `vmecc-frontend/src/routes.js`.
- Visible page, labels, fields, and sequencing: `vmecc-frontend/src/views/settings/components/SalaryWorkflowRules.js`.
- API registration and middleware boundary: `vmecc-backend/routes/api.php`.
- Validation, authorization, or workflow enforcement: `vmecc-backend/app/Http/Controllers/SettingsController.php`.

## Verification coverage

- Focused automated evidence: `vmecc-backend/tests/Feature/PayrollClaimWorkflowRbacTest.php`.

## Discrepancies

No unresolved guide-to-code discrepancy remains after the final-label and workflow audit.
