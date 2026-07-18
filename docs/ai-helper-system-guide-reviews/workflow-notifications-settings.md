# Review dossier: workflow-notifications-settings

Status: version 3 final workflow verification.

- Contract: `/notifications/workflow`; authenticated user; module `workflow_notifications`.
- UI/API evidence: workflow notification view/hook, controller/service/link resolver and tests.
- Verified behavior: personal visible/read/dismissed state; target record remains separately authorized.
- Discrepancy resolved: the former settings-only guide was corrected to the signed-in user's notification inbox and no settings permission.
- Security: another user's state and unauthorized target records are not exposed.

- Frontend route evidence: `vmecc-frontend/src/routes.js`.
- Backend route and authorization evidence: `vmecc-backend/routes/api.php`.
## Verified user workflow

- Route registration: `vmecc-frontend/src/routes.js`.
- Visible page, labels, fields, and sequencing: `vmecc-frontend/src/views/notifications/workflow/WorkflowNotifications.js`.
- API registration and middleware boundary: `vmecc-backend/routes/api.php`.
- Validation, authorization, or workflow enforcement: `vmecc-backend/app/Http/Controllers/WorkflowNotificationController.php`.

## Verification coverage

- Focused automated evidence: `vmecc-backend/tests/Feature/WorkflowNotificationServiceTest.php`.

## Discrepancies

No unresolved guide-to-code discrepancy remains after the final-label and workflow audit.
