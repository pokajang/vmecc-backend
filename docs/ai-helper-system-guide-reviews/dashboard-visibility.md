# Review dossier: dashboard-visibility

Status: version 3 final workflow verification.

- Contract: `/settings/dashboard-visibility`; `settings.manage`; module `settings.dashboard_visibility`.
- UI/API evidence: dashboard visibility matrix/constants, role-permission API, dashboard authorization tests.
- Verified behavior: this is a filtered view of the same role-permission matrix, not a separate access store.
- Security: dashboard visibility never replaces underlying record permissions or modules.
- Recovery: restore the previous matrix and refresh sessions.

- Frontend route evidence: `vmecc-frontend/src/routes.js`.
- Backend route and authorization evidence: `vmecc-backend/routes/api.php`.
## Verified user workflow

- Route registration: `vmecc-frontend/src/routes.js`.
- Visible page, labels, fields, and sequencing: `vmecc-frontend/src/views/settings/DashboardVisibilityMatrix.js`.
- API registration and middleware boundary: `vmecc-backend/routes/api.php`.
- Validation, authorization, or workflow enforcement: `vmecc-backend/app/Http/Controllers/RolePermissionController.php`.

## Verification coverage

- Focused automated evidence: `vmecc-backend/tests/Feature/ModuleActivationApiTest.php`.

## Discrepancies

No unresolved guide-to-code discrepancy remains after the final-label and workflow audit.
