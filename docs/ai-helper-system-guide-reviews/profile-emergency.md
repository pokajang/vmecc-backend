# Review dossier: profile-emergency

Status: candidate authored; Human Resources approval pending.

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
13. Approval: owner Human Resources; v3 body; approval reference, approver, date, and SHA-256 pending.
