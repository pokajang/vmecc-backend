# Review dossier: role-assignments

Status: version 3 final workflow verification.

- Contract: `/admin/users`; `roles.assign`; module `users`.
- UI/API evidence: user role-assignment UI; `UserManagementController`; assignment models/services and tests.
- Validation: global, office, site, and client_site scopes; scoped assignments require their team/context and valid dates.
- Verified behavior: replace, add, edit, and delete assignments are distinct from editing role permissions.
- Security: active assignment scope determines effective authorization; Ask AI cannot grant a role.

- Frontend route evidence: `vmecc-frontend/src/routes.js`.
- Backend route and authorization evidence: `vmecc-backend/routes/api.php`.
## Verified user workflow

- Route registration: `vmecc-frontend/src/routes.js`.
- Visible page, labels, fields, and sequencing: `vmecc-frontend/src/views/users/UserProfile.js`.
- API registration and middleware boundary: `vmecc-backend/routes/api.php`.
- Validation, authorization, or workflow enforcement: `vmecc-backend/app/Http/Controllers/UserManagementController.php`.

## Verification coverage

- Focused automated evidence: `vmecc-backend/tests/Feature/UserManagementSecurityTest.php`.

## Discrepancies

No unresolved guide-to-code discrepancy remains after the final-label and workflow audit.
