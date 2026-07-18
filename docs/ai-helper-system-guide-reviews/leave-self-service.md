# Review dossier: leave-self-service

Status: version 3 final workflow verification.

1. Frontend route/guard: Open **Leave** at /leave, **Apply Leave** at /leave/new, or a request at /leave/:leaveId.
2. Visible workflow: the guide's **Steps** section records the current visible action sequence and separation between self-service, management, and settings.
3. API endpoints: traced in `vmecc-backend/routes/api.php` for the route family named in the guide.
4. Controller/request: `vmecc-frontend/src/views/leave/hooks/useLeaveForm.js`; `vmecc-backend/app/Http/Controllers/LeaveController.php`; `vmecc-backend/app/Http/Controllers/LeaveAttachmentController.php`; `vmecc-backend/app/Services/LeaveWorkflowService.php`.
5. Access: canonical permission and module gate are fixed by `AiHelperSystemGuideCatalog`; management remains assignment-scoped.
6. Module activation: server-owned module gate from guide frontmatter.
7. Model/validation: Type required/max 100; end date on/after start; shift/slots max 50; reason required/max 2,000; cover-by max 255; days non-negative; expected version at least 1.
8. Workflow/next actors: Draft -> Pending review -> optional recommend -> approve. Outcomes: Approved, Rejected, Cancelled, Needs Correction.
9. Attachments: recorded in the guide's **If something goes wrong** section; attachment authorization remains server-side.
10. Errors/recovery: version, state, stage, role, scope, and validation failures are fail-closed as documented.
11. Tests: workflow state-machine, role-catalog matrix, RBAC feature tests, and workflow security/configuration audit tests.
12. Discrepancies: WF-RBAC-001 through WF-RBAC-008 remediation was completed before this final verification; generic template prose was removed.
## Verified user workflow

- Route registration: `vmecc-frontend/src/routes.js`.
- Visible page, labels, fields, and sequencing: `vmecc-frontend/src/views/leave/Leave.js`.
- API registration and middleware boundary: `vmecc-backend/routes/api.php`.
- Validation, authorization, or workflow enforcement: `vmecc-backend/app/Http/Controllers/LeaveController.php`.

## Verification coverage

- Focused automated evidence: `vmecc-backend/tests/Feature/LeaveHardeningTest.php`.

## Discrepancies

No unresolved guide-to-code discrepancy remains after the final-label and workflow audit.
