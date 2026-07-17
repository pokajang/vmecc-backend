# Review dossier: profile-security

Status: candidate authored; System Administration approval pending.

1. Frontend route/guard: `/profile`, `/profile/security`; authenticated Profile module.
2. Page and labels: `AccountSection.js`, `SecuritySection.js`; Edit, Save changes, Current password, New password, Confirm new password.
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
13. Approval: owner System Administration; v3 body; approval reference, approver, date, and SHA-256 pending.
