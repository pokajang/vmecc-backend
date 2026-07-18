# Review dossier: profile-medical

Status: version 3 final workflow verification.

1. Frontend route/guard: `/profile`; permission-filtered Critical Medical Info section.
2. Page and labels: `MedicalSection.js`; Critical Medical Info, No known critical medical info, Blood type, Allergies, Conditions, Medications, Medical notes.
3. API: `PUT /api/profile`.
4. Controller/request: inline medical validation in `AuthController::updateProfile`.
5. Access: `self.profile.medical`; own record only.
6. Module: profile.
7. Validation: bloodType max 50; list entries max 255; notes max 1,000.
8. State/next actor: edit/view; user maintains current details.
9. Attachments: none.
10. Recovery: correct over-limit entries; emergency events follow approved emergency procedure.
11. Tests: frontend `MedicalSection.test.jsx` and profile API coverage.
12. Discrepancies: prior generic form text omitted the no-known-info behavior and privacy boundary.

- Frontend route evidence: `vmecc-frontend/src/routes.js`.
- Backend route and authorization evidence: `vmecc-backend/routes/api.php`.
## Verified user workflow

- Route registration: `vmecc-frontend/src/routes.js`.
- Visible page, labels, fields, and sequencing: `vmecc-frontend/src/views/profile/MedicalSection.js`.
- API registration and middleware boundary: `vmecc-backend/routes/api.php`.
- Validation, authorization, or workflow enforcement: `vmecc-backend/app/Http/Controllers/AuthController.php`.

## Verification coverage

- Focused automated evidence: `vmecc-backend/tests/Feature/UserManagementSecurityTest.php`.

## Discrepancies

No unresolved guide-to-code discrepancy remains after the final-label and workflow audit.
