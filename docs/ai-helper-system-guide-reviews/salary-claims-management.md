# Review dossier: salary-claims-management

Status: version 3 final workflow verification.

- Contract: `/staff/salary-claims`; `staff.salary.manage`; module `payroll.salary_claims_management`.
- UI/API evidence: salary-claims management views; `SalaryWorkflowController`; `SalaryWorkflowService`; backend workflow tests.
- Verified workflow: check, review, approve, reject, and cancel follow stored state, configured roles, scope, and optimistic version.
- Security: `staff.salary.manage` does not grant `staff.salary.pay`.
- Recovery: stale versions reload; invalid state or actor fails closed.

- Frontend route evidence: `vmecc-frontend/src/routes.js`.
- Backend route and authorization evidence: `vmecc-backend/routes/api.php`.
## Verified user workflow

- Route registration: `vmecc-frontend/src/routes.js`.
- Visible page, labels, fields, and sequencing: `vmecc-frontend/src/views/staff/SalaryClaimsManagement.js`.
- API registration and middleware boundary: `vmecc-backend/routes/api.php`.
- Validation, authorization, or workflow enforcement: `vmecc-backend/app/Http/Controllers/PayrollClaimWorkflowController.php`.

## Verification coverage

- Focused automated evidence: `vmecc-backend/tests/Feature/PayrollClaimWorkflowRbacTest.php`.

## Discrepancies

No unresolved guide-to-code discrepancy remains after the final-label and workflow audit.
