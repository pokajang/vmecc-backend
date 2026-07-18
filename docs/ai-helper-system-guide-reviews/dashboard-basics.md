# Review dossier: dashboard-basics

Status: version 3 final workflow verification.

1. Frontend route/guard: `/dashboard`; Dashboard module.
2. Page and labels: `Dashboard.js`; summary cards for Payroll, Overtime, Leave, Roster, Reports and the action queue.
3. API: `GET /api/stats/summary|payroll|overtime|leave|roster|reports`; `GET /api/dashboard/action-queue`.
4. Controller/request: `DashboardController`; `ActionQueueController`.
5. Access: `self.dashboard`, plus the card-specific dashboard permission and module gate.
6. Module: dashboard and the corresponding card module.
7. Validation: endpoints are read-only and use authenticated assignment scope.
8. State/next actor: loading/empty/data/error; destination page owns all transitions.
9. Attachments: none.
10. Recovery: reload once; missing cards are treated as access/module configuration, not inferred.
11. Tests: frontend `Dashboard.test.jsx`, `useDashboardStats.test.js`; backend dashboard/action-queue coverage.
12. Discrepancies: generic mutation language removed from the read-only dashboard guide.

- Frontend route evidence: `vmecc-frontend/src/routes.js`.
- Backend route and authorization evidence: `vmecc-backend/routes/api.php`.
## Verified user workflow

- Route registration: `vmecc-frontend/src/routes.js`.
- Visible page, labels, fields, and sequencing: `vmecc-frontend/src/views/dashboard/Dashboard.js`.
- API registration and middleware boundary: `vmecc-backend/routes/api.php`.
- Validation, authorization, or workflow enforcement: `vmecc-backend/app/Http/Controllers/DashboardController.php`.

## Verification coverage

- Focused automated evidence: `vmecc-backend/tests/Feature/DashboardStatsApiTest.php`.

## Discrepancies

No unresolved guide-to-code discrepancy remains after the final-label and workflow audit.
