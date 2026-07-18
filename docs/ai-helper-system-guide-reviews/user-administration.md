# Review dossier: user-administration

Status: version 3 final workflow verification.

- Contract: `/admin/users`; `users.manage`; module `users`.
- UI/API evidence: user-management views/hooks; `UserManagementController`; account-state and deletion tests.
- Verified behavior: create, activate/deactivate, lock/unlock, reset password, delete, restore, and state-quality reporting.
- Security: self-lock and self-delete protections apply; account disable/lock/delete revokes active sessions.
- Separation: role assignment requires the distinct `roles.assign` permission.

- Frontend route evidence: `vmecc-frontend/src/routes.js`.
- Backend route and authorization evidence: `vmecc-backend/routes/api.php`.
## Verified user workflow

- Route registration: `vmecc-frontend/src/routes.js`.
- Visible page, labels, fields, and sequencing: `vmecc-frontend/src/views/users/UserManagement.js`.
- API registration and middleware boundary: `vmecc-backend/routes/api.php`.
- Validation, authorization, or workflow enforcement: `vmecc-backend/app/Http/Controllers/UserManagementController.php`.

## Verification coverage

- Focused automated evidence: `vmecc-backend/tests/Feature/UserManagementSecurityTest.php`.

## Discrepancies

No unresolved guide-to-code discrepancy remains after the final-label and workflow audit.
