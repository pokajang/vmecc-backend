# Review dossier: payment-actions

Status: version 3 final workflow verification.

- Contract: `/staff/salary-claims`; `staff.salary.pay`; module `payroll.payment_actions`.
- UI/API evidence: Salary Records and Claim Records payment controls; `PayrollClaimManagementController`; payment feature tests; `routes/api.php`.
- Validation: payment date required, reference max 255, note max 2,000, expected version at least 1; bulk size 1 to 200.
- Verified workflow: only approved records can be marked paid; unmark requires a reason up to 1,000 characters.
- Security: payment permission is isolated from salary management and Ask AI cannot execute a payment.

- Frontend route evidence: `vmecc-frontend/src/routes.js`.
- Backend route and authorization evidence: `vmecc-backend/routes/api.php`.
## Verified user workflow

- Route registration: `vmecc-frontend/src/routes.js`.
- Visible page, labels, fields, and sequencing: `vmecc-frontend/src/views/staff/salary-claims-management/components/SalaryRecordsTab.js`.
- API registration and middleware boundary: `vmecc-backend/routes/api.php`.
- Validation, authorization, or workflow enforcement: `vmecc-backend/app/Http/Controllers/PayrollClaimManagementController.php`.

## Verification coverage

- Focused automated evidence: `vmecc-backend/tests/Feature/PayrollClaimPaymentWorkflowApiTest.php`.

## Discrepancies

No unresolved guide-to-code discrepancy remains after the final-label and workflow audit.
