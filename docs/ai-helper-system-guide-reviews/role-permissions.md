# Review dossier: role-permissions

Status: version 3 final workflow verification.

- Contract: `/settings/role-permissions`; `settings.manage`; module `settings.role_permissions`.
- UI/API evidence: permission matrix/hooks, `RolePermissionController`, `RoleCatalog`, RBAC tests.
- Verified behavior: submitted non-admin roles sync known permissions and clear the permission cache.
- Security: unknown roles are ignored, unknown permissions stripped, and System Administrator locked.
- Recovery: prior matrix is the rollback source.

- Frontend route evidence: `vmecc-frontend/src/routes.js`.
- Backend route and authorization evidence: `vmecc-backend/routes/api.php`.
## Verified user workflow

- Route registration: `vmecc-frontend/src/routes.js`.
- Visible page, labels, fields, and sequencing: `vmecc-frontend/src/views/settings/RolePermissionMatrix.js`.
- API registration and middleware boundary: `vmecc-backend/routes/api.php`.
- Validation, authorization, or workflow enforcement: `vmecc-backend/app/Http/Controllers/RolePermissionController.php`.

## Verification coverage

- Focused automated evidence: `vmecc-backend/tests/Unit/WorkflowRoleCatalogMatrixTest.php`.

## Discrepancies

No unresolved guide-to-code discrepancy remains after the final-label and workflow audit.
