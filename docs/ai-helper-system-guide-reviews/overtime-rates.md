# Review dossier: overtime-rates

Status: version 3 candidate authored; Human Resources approval pending.

1. Frontend route/guard: Open /staff/overtime-management and select rate settings.
2. Visible workflow: the guide's **Exact steps** section records the current visible action sequence and separation between self-service, management, and settings.
3. API endpoints: traced in `vmecc-backend/routes/api.php` for the route family named in the guide.
4. Controller/request: `vmecc-frontend/src/views/staff/overtime-management/OvertimeManagement.js`; `vmecc-backend/app/Http/Controllers/SettingsController.php`; `vmecc-backend/app/Services/OvertimeWorkflowService.php`.
5. Access: canonical permission and module gate are fixed by `AiHelperSystemGuideCatalog`; management remains assignment-scoped.
6. Module activation: server-owned module gate from guide frontmatter.
7. Model/validation: Multipliers 0â€“100; divisor 0â€“366; hours/day 0â€“24; mode/strategy registered; role names exist.
8. Workflow/next actors: Settings are normalized and audited; new calculations use current values.
9. Attachments: recorded in the guide's **Attachments and limits** section; attachment authorization remains server-side.
10. Errors/recovery: version, state, stage, role, scope, and validation failures are fail-closed as documented.
11. Tests: workflow state-machine, role-catalog matrix, RBAC feature tests, and workflow security/configuration audit tests.
12. Discrepancies: WF-RBAC-001 through WF-RBAC-008 remediation was completed before this candidate; generic template prose was removed.
13. Approval: owner Human Resources; v3 body; approval reference, approver, approval date, and final SHA-256 pending.
