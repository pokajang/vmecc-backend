# Review dossier: roster-manage

Status: version 3 final workflow verification.

- Contract: roster management; `rosters.manage`; module `roster`.
- UI/API evidence: roster management views; `RosterController`; roster validation, publication, and notification tests.
- Validation: 1 to 500 entries; valid date/shift/team; one team cannot occupy two shifts on the same date; publish scope label max 100.
- Verified behavior: save retains draft state; publish creates the published scope and notifications.
- Security: team and shift references remain server validated.

- Frontend route evidence: `vmecc-frontend/src/routes.js`.
- Backend route and authorization evidence: `vmecc-backend/routes/api.php`.
## Verified user workflow

- Route registration: `vmecc-frontend/src/routes.js`.
- Visible page, labels, fields, and sequencing: `vmecc-frontend/src/views/roster/RosterManagement.js`.
- API registration and middleware boundary: `vmecc-backend/routes/api.php`.
- Validation, authorization, or workflow enforcement: `vmecc-backend/app/Http/Controllers/RosterController.php`.

## Verification coverage

- Focused automated evidence: `vmecc-backend/tests/Feature/RosterControllerTest.php`.

## Discrepancies

No unresolved guide-to-code discrepancy remains after the final-label and workflow audit.
