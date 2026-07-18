# Review dossier: payroll-statutory-rates

Status: version 3 final workflow verification.

- Contract: payroll configuration; `settings.manage` or `staff.salary.manage`; module `payroll.statutory_rates`.
- UI/API evidence: Salary Assignments displays the calculated EPF, PERKESO, and SIP deductions; the rate API has no rendered editor.
- Validation: salary-assignment inputs and calculated deduction rows are verified before the assignment is confirmed.
- Verified behavior: the guide does not invent a configuration page; deployed rates affect later calculations and historical payroll must be reviewed separately.
- Security: retrieval follows permission-any semantics and the module gate.

- Frontend route evidence: `vmecc-frontend/src/routes.js`.
- Backend route and authorization evidence: `vmecc-backend/routes/api.php`.
## Verified user workflow

- Route registration: `vmecc-frontend/src/routes.js`.
- Visible page, labels, fields, and sequencing: `vmecc-frontend/src/views/staff/salary-claims-management/components/SalaryAssignmentFormSections.js`.
- API registration and middleware boundary: `vmecc-backend/routes/api.php`.
- Validation, authorization, or workflow enforcement: `vmecc-backend/app/Http/Controllers/SettingsController.php`.

## Verification coverage

- Focused automated evidence: `vmecc-backend/tests/Feature/SettingsControllerTest.php`.

## Discrepancies

The prior draft described a standalone Statutory Rates page that is not rendered. The final guide now limits the user workflow to reviewing calculated deductions in Salary Assignments.
