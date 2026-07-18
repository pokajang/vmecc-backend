# Review dossier: profile-security

Status: version 3 final workflow verification.

1. Frontend route/guard: `/profile`, `/profile/security`; authenticated Profile module.
2. Page and labels: `AccountSection.js`, `SecuritySection.js`; Personal, Security, Edit, Save changes, Current password, New password, Confirm new password.
3. API: `PUT /api/profile`, `POST|DELETE /api/profile/image`, `POST /api/auth/password`.
4. Controller/request: inline validation in `AuthController`.
5. Access: authenticated user; own record only.
6. Module: profile.
7. Validation: exact length/state rules in `AuthController::updateProfile`; password minimum 8 and confirmed; image JPG/JPEG/PNG/WebP up to 2 MB.
8. State/next actor: saved profile or changed password; password change revokes other sessions.
9. Attachments: profile image only, image MIME and 2 MB maximum.
10. Recovery: named validation error; password-reset flow for unknown current password.
11. Tests: auth/profile feature coverage and frontend profile section tests.
12. Discrepancies: prior text omitted exact fields, limits, and session revocation.

- Frontend route evidence: `vmecc-frontend/src/routes.js`.
- Backend route and authorization evidence: `vmecc-backend/routes/api.php`.
## Verified user workflow

- Route registration: `vmecc-frontend/src/routes.js`.
- Visible page, labels, fields, and sequencing: `vmecc-frontend/src/views/profile/SecuritySection.js`.
- API registration and middleware boundary: `vmecc-backend/routes/api.php`.
- Validation, authorization, or workflow enforcement: `vmecc-backend/app/Http/Controllers/AuthController.php`.

## Verification coverage

- Focused automated evidence: `vmecc-backend/tests/Feature/UserManagementSecurityTest.php`.

## Discrepancies

No unresolved guide-to-code discrepancy remains after the final-label and workflow audit.
