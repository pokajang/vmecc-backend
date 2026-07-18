# Review dossier: profile-emergency

Status: version 3 final workflow verification.

1. Frontend route/guard: `/profile`; permission-filtered Emergency Contact section.
2. Page and labels: `EmergencySection.js`; contact name, Relationship, Mobile number, Email, Home address.
3. API: `PUT /api/profile`.
4. Controller/request: inline emergency-contact validation in `AuthController::updateProfile`.
5. Access: `self.profile.emergency`; own record only.
6. Module: profile.
7. Validation: name 255, relationship 100, phone 50, email valid/max 255, address 500.
8. State/next actor: edit/view; user keeps contact current.
9. Attachments: none.
10. Recovery: correct email/length errors; urgent incidents use emergency procedure.
11. Tests: EmergencySection behavior and profile API coverage.
12. Discrepancies: generic form text replaced with exact fields and limits.

- Frontend route evidence: `vmecc-frontend/src/routes.js`.
- Backend route and authorization evidence: `vmecc-backend/routes/api.php`.
## Verified user workflow

- Route registration: `vmecc-frontend/src/routes.js`.
- Visible page, labels, fields, and sequencing: `vmecc-frontend/src/views/profile/EmergencySection.js`.
- API registration and middleware boundary: `vmecc-backend/routes/api.php`.
- Validation, authorization, or workflow enforcement: `vmecc-backend/app/Http/Controllers/AuthController.php`.

## Verification coverage

- Focused automated evidence: `vmecc-backend/tests/Feature/UserManagementSecurityTest.php`.

## Discrepancies

No unresolved guide-to-code discrepancy remains after the final-label and workflow audit.
