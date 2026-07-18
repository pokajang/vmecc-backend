# Review dossier: teams-manage

Status: version 3 final workflow verification.

- Contract: team management; `teams.manage`; module `teams.directory`.
- UI/API evidence: team create/edit/member UI; `TeamController`; `TeamMemberSyncService`; team tests.
- Validation: team name required unique max 255; member user active; image JPEG/PNG/WebP/GIF max 4,096 KB.
- Verified behavior: one primary member and active cross-team conflicts are validated; scoped update/upload checks target team.
- Security: create/delete and scoped update boundaries remain distinct.

- Frontend route evidence: `vmecc-frontend/src/routes.js`.
- Backend route and authorization evidence: `vmecc-backend/routes/api.php`.
## Verified user workflow

- Route registration: `vmecc-frontend/src/routes.js`.
- Visible page, labels, fields, and sequencing: `vmecc-frontend/src/views/team/TeamDetails.js`.
- API registration and middleware boundary: `vmecc-backend/routes/api.php`.
- Validation, authorization, or workflow enforcement: `vmecc-backend/app/Http/Controllers/TeamController.php`.

## Verification coverage

- Focused automated evidence: `vmecc-backend/tests/Feature/TeamControllerTest.php`.

## Discrepancies

No unresolved guide-to-code discrepancy remains after the final-label and workflow audit.
