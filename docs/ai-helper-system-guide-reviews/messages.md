# Review dossier: messages

Status: version 3 final workflow verification.

1. Frontend route/guard: `/messages`; Messages module.
2. Page and labels: `Messages.js`, `ChatList.js`, `ChatThread.js`; New chat, Unread, Send message, Delete message, Delete conversation.
3. API: contacts, threads, read, message create/delete, attachment create/read/delete routes under `/api/messages`.
4. Controller/request: inline validation in `MessageController` and `MessageAttachmentController`.
5. Access: `self.messages`; attachment restricted to sender or recipient.
6. Module: messages.
7. Validation: recipient is active user; subject 255; body 2,000; body or attachment required.
8. State/next actor: sent/received/read; latest sent message can show Seen; deletion scope enforced server-side.
9. Attachments: one JPG/JPEG/PNG/GIF/WebP image, 2 MB; client longest dimension 1,920.
10. Recovery: remove/reselect rejected image; reload threads once; local draft persistence.
11. Tests: message UI tests plus controller/API tests.
12. Discrepancies: template omitted deletion scope, attachment authorization, and exact limits.

- Frontend route evidence: `vmecc-frontend/src/routes.js`.
- Backend route and authorization evidence: `vmecc-backend/routes/api.php`.
## Verified user workflow

- Route registration: `vmecc-frontend/src/routes.js`.
- Visible page, labels, fields, and sequencing: `vmecc-frontend/src/views/messages/Messages.js`.
- API registration and middleware boundary: `vmecc-backend/routes/api.php`.
- Validation, authorization, or workflow enforcement: `vmecc-backend/app/Http/Controllers/MessageController.php`.

## Verification coverage

- Focused automated evidence: `vmecc-backend/tests/Feature/UserManagementSecurityTest.php`.

## Discrepancies

No unresolved guide-to-code discrepancy remains after the final-label and workflow audit.
