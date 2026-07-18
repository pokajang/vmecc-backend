# Review dossier: overtime-rates

Status: version 3 final workflow verification.

1. Frontend route/guard: open `/staff/set-salary/set-ot-rate` through Payroll Configuration > Overtime Rates.
2. Visible workflow: the guide's **Steps** section records the current visible action sequence and separation between self-service, management, and settings.
3. API endpoints: traced in `vmecc-backend/routes/api.php` for the route family named in the guide.
4. Controller/request: `vmecc-frontend/src/views/staff/salary-claims-management/components/OvertimeRateSettingsTab.js`; `vmecc-backend/app/Http/Controllers/SettingsController.php`.
5. Access: canonical permission and module gate are fixed by `AiHelperSystemGuideCatalog`; management remains assignment-scoped.
6. Module activation: server-owned module gate from guide frontmatter.
7. Model/validation: Multipliers 0-100; divisor 0-366; hours/day 0-24; mode/strategy registered; role names exist.
8. Workflow/next actors: Settings are normalized and audited; new calculations use current values.
9. Attachments: recorded in the guide's **If something goes wrong** section; attachment authorization remains server-side.
10. Errors/recovery: version, state, stage, role, scope, and validation failures are fail-closed as documented.
11. Tests: workflow state-machine, role-catalog matrix, RBAC feature tests, and workflow security/configuration audit tests.
12. Discrepancies: WF-RBAC-001 through WF-RBAC-008 remediation was completed before this final verification; generic template prose was removed.
## Verified user workflow

- Route registration: `vmecc-frontend/src/routes.js`.
- Visible page, labels, fields, and sequencing: `vmecc-frontend/src/views/staff/salary-claims-management/components/OvertimeRateSettingsTab.js`.
- API registration and middleware boundary: `vmecc-backend/routes/api.php`.
- Validation, authorization, or workflow enforcement: `vmecc-backend/app/Http/Controllers/SettingsController.php`.

## Verification coverage

- Focused automated evidence: `vmecc-backend/tests/Feature/SettingsControllerTest.php`.

## Discrepancies

The prior draft used the Overtime Management entry point. The final guide now uses Payroll Configuration > Overtime Rates.
