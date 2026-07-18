# Review dossier: profile-banking

Status: version 3 final workflow verification.

1. Frontend route/guard: `/profile`; permission-filtered Banking Info section.
2. Page and labels: `BankingSection.js`; Banking Info, Bank, Account name, Account number, Edit, Save changes.
3. API: `PUT /api/profile`.
4. Controller/request: inline banking validation in `AuthController::updateProfile`.
5. Access: `self.profile.banking`; own record only.
6. Module: profile.
7. Validation: bankName/accountName nullable strings max 255; accountNumber nullable string max 50.
8. State/next actor: edit/view; user verifies masked account number.
9. Attachments: none.
10. Recovery: correct named field; established HR escalation for payroll-impacting corrections.
11. Tests: profile API coverage and BankingSection behavior.
12. Discrepancies: generic profile text replaced; salary/payment administration explicitly excluded.

- Frontend route evidence: `vmecc-frontend/src/routes.js`.
- Backend route and authorization evidence: `vmecc-backend/routes/api.php`.
## Verified user workflow

- Route registration: `vmecc-frontend/src/routes.js`.
- Visible page, labels, fields, and sequencing: `vmecc-frontend/src/views/profile/BankingSection.js`.
- API registration and middleware boundary: `vmecc-backend/routes/api.php`.
- Validation, authorization, or workflow enforcement: `vmecc-backend/app/Http/Controllers/AuthController.php`.

## Verification coverage

- Focused automated evidence: `vmecc-backend/tests/Feature/UserManagementSecurityTest.php`.

## Discrepancies

No unresolved guide-to-code discrepancy remains after the final-label and workflow audit.
