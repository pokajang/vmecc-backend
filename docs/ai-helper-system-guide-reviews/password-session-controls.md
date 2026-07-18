# Review dossier: password-session-controls

Status: version 3 final workflow verification.

- Contract: `/admin/users`; `users.manage`; module `users`.
- UI/API evidence: user security controls; reset, lock, unlock, session-list, and revoke endpoints; auth security tests.
- Verified behavior: reset sends a link; session actions revoke one or all server sessions; lock blocks authentication.
- Security: self-protection and audit logging remain server enforced; passwords are never displayed.
- Recovery: verify the target account and current state before a disruptive action.

- Frontend route evidence: `vmecc-frontend/src/routes.js`.
- Backend route and authorization evidence: `vmecc-backend/routes/api.php`.
## Verified user workflow

- Route registration: `vmecc-frontend/src/routes.js`.
- Visible page, labels, fields, and sequencing: `vmecc-frontend/src/components/users/UserSessionsPanel.js`.
- API registration and middleware boundary: `vmecc-backend/routes/api.php`.
- Validation, authorization, or workflow enforcement: `vmecc-backend/app/Http/Controllers/UserManagementController.php`.

## Verification coverage

- Focused automated evidence: `vmecc-backend/tests/Feature/UserManagementSecurityTest.php`.

## Discrepancies

No unresolved guide-to-code discrepancy remains after the final-label and workflow audit.
