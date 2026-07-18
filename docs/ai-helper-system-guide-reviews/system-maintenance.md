# Review dossier: system-maintenance

Status: version 3 final workflow verification.

- Contract: `/settings`; `settings.manage`; module `settings.system_maintenance`.
- UI/API evidence: Settings maintenance controls/storage, `SettingsController`, maintenance service/middleware and tests.
- Validation: boolean enabled; message max 500; off/grace/enforced phase; date fields.
- Verified workflow: Off -> Grace -> Enforced and return to Off; changes are audited.
- Security: operational authorization and health checks remain outside Ask AI.

- Frontend route evidence: `vmecc-frontend/src/routes.js`.
- Backend route and authorization evidence: `vmecc-backend/routes/api.php`.
## Verified user workflow

- Route registration: `vmecc-frontend/src/routes.js`.
- Visible page, labels, fields, and sequencing: `vmecc-frontend/src/views/settings/Settings.js`.
- API registration and middleware boundary: `vmecc-backend/routes/api.php`.
- Validation, authorization, or workflow enforcement: `vmecc-backend/app/Http/Controllers/SettingsController.php`.

## Verification coverage

- Focused automated evidence: `vmecc-backend/tests/Feature/SettingsControllerTest.php`.

## Discrepancies

No unresolved guide-to-code discrepancy remains after the final-label and workflow audit.
