# Review dossier: payroll-company-profile

Status: version 3 final workflow verification.

- Contract: payroll configuration; `settings.manage` or `staff.salary.manage`; module `payroll.company_profile`.
- UI/API evidence: payroll company settings; `SettingsController`; payroll profile persistence and tests.
- Validation: legal name max 255; registration/tax IDs max 100; address max 500; valid email max 255; phone fields max 50.
- Verified behavior: profile values feed payroll documents and remain separate from employee banking data.
- Security: server permission-any and module activation checks apply.

- Frontend route evidence: `vmecc-frontend/src/routes.js`.
- Backend route and authorization evidence: `vmecc-backend/routes/api.php`.
## Verified user workflow

- Route registration: `vmecc-frontend/src/routes.js`.
- Visible page, labels, fields, and sequencing: `vmecc-frontend/src/views/staff/salary-claims-management/components/CompanyLegalInfoTab.js`.
- API registration and middleware boundary: `vmecc-backend/routes/api.php`.
- Validation, authorization, or workflow enforcement: `vmecc-backend/app/Http/Controllers/SettingsController.php`.

## Verification coverage

- Focused automated evidence: `vmecc-backend/tests/Feature/PayrollCompanyProfileSettingsApiTest.php`.

## Discrepancies

No unresolved guide-to-code discrepancy remains after the final-label and workflow audit.
