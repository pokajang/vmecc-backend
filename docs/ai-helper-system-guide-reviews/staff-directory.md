# Review dossier: staff-directory

Status: version 3 final workflow verification.

- Contract: `/staff/details`; `staff.view`; module `staff.directory`.
- UI/API evidence: staff directory/detail views; user listing/profile controllers; assignment authorization tests.
- Verified behavior: search and read of staff fields stays within effective assignment scope.
- Security: banking, medical, emergency, and management fields retain their own permissions.
- Recovery: a missing profile may be outside scope rather than absent.

- Frontend route evidence: `vmecc-frontend/src/routes.js`.
- Backend route and authorization evidence: `vmecc-backend/routes/api.php`.
## Verified user workflow

- Route registration: `vmecc-frontend/src/routes.js`.
- Visible page, labels, fields, and sequencing: `vmecc-frontend/src/views/staff/StaffDetails.js`.
- API registration and middleware boundary: `vmecc-backend/routes/api.php`.
- Validation, authorization, or workflow enforcement: `vmecc-backend/app/Http/Controllers/UserManagementController.php`.

## Verification coverage

- Focused automated evidence: `vmecc-backend/tests/Feature/UserManagementSecurityTest.php`.

## Discrepancies

No unresolved guide-to-code discrepancy remains after the final-label and workflow audit.
