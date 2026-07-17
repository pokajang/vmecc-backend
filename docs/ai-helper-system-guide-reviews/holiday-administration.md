# Review dossier: holiday-administration

Status: version 3 candidate authored; Human Resources approval pending.

1. Frontend route/guard: Open /staff/leave-management/set-holidays.
2. Visible workflow: the guide's **Exact steps** section records the current visible action sequence and separation between self-service, management, and settings.
3. API endpoints: traced in `vmecc-backend/routes/api.php` for the route family named in the guide.
4. Controller/request: `vmecc-frontend/src/views/staff/leave-management/LeaveManagement.js`; `vmecc-backend/app/Http/Controllers/HolidayController.php`; `vmecc-backend/app/Http/Controllers/LeaveController.php`.
5. Access: canonical permission and module gate are fixed by `AiHelperSystemGuideCatalog`; management remains assignment-scoped.
6. Module activation: server-owned module gate from guide frontmatter.
7. Model/validation: National key max 100; names max 255; date YYYY-MM-DD; applicable boolean; additional scope registered; state nullable/max 100.
8. Workflow/next actors: National rows upsert by fixed key/year; additional rows create/edit/delete and changes are audited.
9. Attachments: recorded in the guide's **Attachments and limits** section; attachment authorization remains server-side.
10. Errors/recovery: version, state, stage, role, scope, and validation failures are fail-closed as documented.
11. Tests: workflow state-machine, role-catalog matrix, RBAC feature tests, and workflow security/configuration audit tests.
12. Discrepancies: WF-RBAC-001 through WF-RBAC-008 remediation was completed before this candidate; generic template prose was removed.
13. Approval: owner Human Resources; v3 body; approval reference, approver, approval date, and final SHA-256 pending.
