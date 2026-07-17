# Review dossier: dashboard-basics

Status: candidate authored; System Administration approval pending.

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
13. Approval: owner System Administration; v3 body; approval reference, approver, date, and SHA-256 pending.
