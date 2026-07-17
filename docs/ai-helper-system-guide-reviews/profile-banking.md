# Review dossier: profile-banking

Status: candidate authored; Human Resources approval pending.

1. Frontend route/guard: `/profile`; permission-filtered Banking Information section.
2. Page and labels: `BankingSection.js`; Bank, Account name, Account number, Edit, Save changes.
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
13. Approval: owner Human Resources; v3 body; approval reference, approver, date, and SHA-256 pending.
