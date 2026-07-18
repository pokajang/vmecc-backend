# Review dossier: salary-assignments

Status: version 3 final workflow verification.

- Contract: `/staff/set-salary`; `staff.salary.manage`; module `payroll.salary_assignments`.
- UI/API evidence: set-salary views; `SalaryAssignmentController`; assignment requests and tests.
- Validation: employee/effective date required; monetary values 0 to 99,999,999.99; up to 50 named allowances; notes max 2,000.
- Verified behavior: effective-dated assignments are distinct from claims, payments, and statutory configuration.
- Security: employee and assignment scope remain server authorized.

- Frontend route evidence: `vmecc-frontend/src/routes.js`.
- Backend route and authorization evidence: `vmecc-backend/routes/api.php`.
## Verified user workflow

- Route registration: `vmecc-frontend/src/routes.js`.
- Visible page, labels, fields, and sequencing: `vmecc-frontend/src/views/staff/salary-claims-management/components/SalaryAssignmentFormPage.js`.
- API registration and middleware boundary: `vmecc-backend/routes/api.php`.
- Validation, authorization, or workflow enforcement: `vmecc-backend/app/Http/Controllers/SalaryAssignmentController.php`.

## Verification coverage

- Focused automated evidence: `vmecc-backend/tests/Feature/SalaryAssignmentNotificationTest.php`.

## Discrepancies

No unresolved guide-to-code discrepancy remains after the final-label and workflow audit.
