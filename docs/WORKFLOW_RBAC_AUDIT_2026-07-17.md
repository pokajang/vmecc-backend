# Workflow RBAC Audit and Test Matrix

Date: 2026-07-17

Scope: `vmecc-backend` and `vmecc-frontend` role-routed workflows

Method: source trace, state-machine tests, API feature tests, notification/action-queue tests, and executable known-gap probes

## Audit objective

Prove that every prepared/submitted record can be processed only by the authenticated actor who owns the current workflow role, permission, assignment window, and scope; that actions occur in order; and that every successful transition records immutable actor attribution and updates the next action owner.

Frontend visibility is not treated as an authorization boundary. A workflow is considered protected only when a direct API request is denied without persistence or notification side effects.

## Sources of truth

| Concern | Authoritative implementation |
|---|---|
| Active roles and assignment windows | `AssignmentAuthorizationService` |
| Role/permission catalog | `RoleCatalog` |
| Leave state machine | `LeaveWorkflowService` and `LeaveWorkflowController` |
| Overtime state machine and team scope | `OvertimeWorkflowService`, `OvertimeWorkflowController`, `OvertimeManagementScopeService` |
| Payroll claim state machine | `PayrollClaimWorkflowService` and `PayrollClaimWorkflowController` |
| Report state machine | `ReportingWorkflowService` and `ReportController` |
| Inspection reporting specialization | `InspectionWorkflowService`, `InspectionPolicy`, and `InspectionSessionController` |
| Notifications | `WorkflowNotificationService` and `LeaveNotificationService` adapter |
| Action queue | `ActionQueueService` |
| Frontend shared role logic | `workflowDomain.js` |
| Frontend display contracts | `workflowContracts.js` |

`workflowContracts.js` is a presentation contract, not a security contract. Backend services/controllers own workflow authorization and persistence.

## Workflow inventory

| Family | Prepared/submitted state | Ordered positive path | Exception paths | Concurrency |
|---|---|---|---|---|
| Leave | `Pending`, stage `review` | `review → recommend → approve` | recommendation may be skipped; reject; correction; applicant resubmit; cancel | `version`, row lock, conditional expected version |
| Overtime | `Pending`, stage `review` | `review → recommend → approve` | recommendation may be skipped; reject; correction; applicant resubmit; admin cancel | `version`, row lock, conditional update |
| Salary/expense claim | `Pending`, stage `check` | `check → review → approve` | reject; cancel; salary mark-paid/unmark-paid | No workflow version or row lock |
| Inspection report | `Submitted`, stage `review` | `review → approve` | reject; same-team AIC; IC fallback; duty confirmation | `version`; invalid transition and stale-write conflicts |
| ERCO report | `Submitted`, stage `review` | `review → approve` | reject; self-review/self-approve policy | `version` |
| Drill report | `Submitted`, stage `review` | `review → approve` | reject; UI labels differ from canonical actions | `version` |
| Fitness-test report | `Submitted`, stage `review` | `review → approve` | reject | `version` |

Administrative moderation for feedback reports, Ask AI response reports, knowledge review, and fire-extinguisher issues is represented in the action queue but is not the same prepared/reviewed/approved state machine. It remains a separate lifecycle and was not forced into the core workflow contract.

## Route and enforcement matrix

| Family | Route gate | Stage owner gate | Scope gate | Self-action gate |
|---|---|---|---|---|
| Leave | `staff.leave.manage` | exact active `next_action_role` | no record team scope | no explicit owner prohibition; normally owner lacks management route |
| Overtime | `staff.overtime.manage` | exact active `next_action_role` | active assignment scope must cover owner team | no explicit owner prohibition; normally owner lacks management route |
| Payroll workflow | module enabled + `staff.salary.manage` | exact active `next_action_role` for check/review/approve | no record team scope | no explicit owner prohibition |
| Payroll payment | module enabled + `staff.salary.pay` | approved/paid status contract | no record team scope | permission based |
| Reports | controller module permission | active workflow role | same-team scope during configured review | configurable, enabled by default |
| Inspection | report/inspection permissions | reporting workflow role | same-team AIC or IC fallback | configurable plus duty-context policy |

## Catalog role matrix

`✓` is granted by the backend catalog; `—` is not granted. System Administrator has wildcard access.

| Role | Leave manage | OT manage | Salary manage | Salary pay | Reports manage |
|---|---:|---:|---:|---:|---:|
| System Administrator | ✓ | ✓ | ✓ | ✓ | ✓ |
| Contract Manager | — | ✓ | — | — | ✓ |
| Human Resource | ✓ | ✓ | ✓ | — | — |
| Finance | — | — | ✓ | ✓ | — |
| Admin | — | — | — | — | — |
| Incident Commander | — | — | — | — | ✓ |
| Assistant Incident Commander | — | — | — | — | ✓ |
| Tactical Response Team | — | — | — | — | ✓ |
| Client Contract Manager | — | ✓ | — | — | — |
| Representative | — | — | — | — | — |

The matrix is locked by `tests/Unit/WorkflowRoleCatalogMatrixTest.php` so permission drift becomes visible.

## Tested invariants

### Positive transitions

- Correct active role plus route permission can process its stage.
- Leave and overtime progress through review, recommendation, and approval.
- Payroll progresses through check, review, and approval.
- Reporting progresses through review and approval.
- Optional recommendation correctly routes review directly to approval.
- Correction removes staff stage ownership and returns the record to the applicant.
- Terminal transitions clear `next_action_role` and set stage `done`.

### Negative authorization

- Permission without the current role is denied.
- An expired stage role is ignored even when another active role grants route access.
- Missing `next_action_role` fails closed for non-administrators.
- Wrong-stage actions are rejected.
- Terminal records cannot be processed again.
- Team-scoped overtime and inspection actions reject wrong-team actors.
- Reporting self-review and self-approval are denied under default policy.
- Distinct-approver policies reject a repeat actor.

### Integrity and attribution

- Leave, overtime, and report stale versions are rejected without mutation.
- Denied actions do not append history or emit notifications.
- Successful histories contain the authenticated actor ID and server-side actor name.
- In-flight reporting snapshots remain authoritative after settings change.
- Leave approval moves balance from pending to used only after valid approval.
- Overtime and payroll histories retain the latest 20 entries; reports retain 30.
- Notifications are created for successful terminal actions.
- Action queues filter by current role, module permission, scope, and self-review policy.

## Automated coverage added

Backend green regression suites:

- `tests/Unit/WorkflowTransitionStateMachineTest.php`
- `tests/Unit/WorkflowRoleCatalogMatrixTest.php`
- `tests/Feature/LeaveWorkflowRbacTest.php`
- `tests/Feature/OvertimeWorkflowRbacMatrixTest.php`
- `tests/Feature/PayrollClaimWorkflowRbacTest.php`
- `tests/Feature/ReportingWorkflowRoleAssignmentTest.php`

Frontend green regression suites:

- expanded `src/views/staff/shared/__tests__/workflowDomain.test.js`
- added `src/views/staff/shared/__tests__/workflowContracts.test.js`

Security-invariant executable probes, retained outside the normal PHPUnit Unit/Feature suites:

- `tests/Audit/WorkflowSecurityInvariantAuditTest.php`
- `tests/Audit/WorkflowConfigurationAuditTest.php`

These probes assert the secure target behavior and are green after the 2026-07-17 remediation. They remain executable regression checks so the confirmed gaps cannot recur silently.

## Corrective action status

| Finding | Status | Implemented control |
|---|---|---|
| WF-RBAC-001 | Remediated | System Administrator bypasses role ownership only; status, stage order, declaration/remarks, distinct-approver policy, and version checks remain mandatory. |
| WF-RBAC-002 | Remediated | Default Admin and Contract Manager payroll roles now receive `staff.salary.manage`; settings reject roles that cannot reach the workflow route. |
| WF-RBAC-003 | Remediated | Payroll rejection requires current-stage ownership; cancellation is an explicit System Administrator exception with mandatory remarks. |
| WF-RBAC-004 | Remediated | Payroll claims now have a version, transactional row locks, stale-write `409` responses, and frontend expected-version propagation, including payment transitions. |
| WF-RBAC-005 | Remediated | Leave uses a non-empty Human Resource fallback and settings validate that stage roles possess `staff.leave.manage`. |
| WF-RBAC-006 | Remediated | Notification detail routing forwards report type/UID and the drawer prefers the normalized deep link. |
| WF-RBAC-007 | Remediated | Reporting uses the same administrator role-override policy while retaining order and self-action restrictions. |
| WF-RBAC-008 | Remediated | Leave JSON history is capped at 30 entries and all workflow families dual-write new entries to an append-only relational transition ledger; existing JSON histories are backfilled by migration. |

## Confirmed findings

### WF-RBAC-001 — High — System Administrator bypasses workflow ordering

Affected: leave, overtime, payroll claims.

The controllers return immediately after detecting `System Administrator`, before validating that the requested action matches the current stage. An administrator can approve a record still at leave/overtime `review` or payroll `check`.

Evidence: all three stage-preservation probes receive HTTP 200 instead of the expected 422.

Recommended remediation: keep the administrator role override for role ownership and permission only; always enforce record status, action/stage compatibility, declarations/remarks, and optimistic locking.

### WF-RBAC-002 — High — Default payroll roles cannot reach their workflow routes

Affected default path: `Admin → Finance → Contract Manager`.

The route requires `staff.salary.manage`. Finance has it, but Admin and Contract Manager do not. Without custom permission changes, the default check and approval owners cannot process their stages.

Evidence: configuration audit fails for Admin and Contract Manager; Finance passes.

Recommended remediation: either grant the route permission to configured stage roles, change safe defaults to roles that possess it, or validate settings so an unreachable role cannot be saved.

### WF-RBAC-003 — High — Payroll reject/cancel bypasses current stage ownership

Any actor who reaches the salary-management route can reject or cancel a claim regardless of `next_action_role`. Leave and overtime enforce different rules, so behavior is inconsistent.

Evidence: an active Human Resource actor rejects an Admin-owned `check` stage and receives HTTP 200.

Recommended remediation: define an explicit rejection/cancellation policy. If current-stage ownership is required, apply the same active-role check used for check/review/approve. If broader authority is intentional, encode a separate permission such as `staff.salary.override` and expose that policy in the UI.

### WF-RBAC-004 — High — Payroll workflow has no optimistic locking

Payroll claims have no `version` column. Workflow actions are loaded, validated, and updated without a row lock or compare-and-swap condition. Concurrent actors can validate the same stage before either update commits.

Evidence: schema probe confirms `payroll_claims.version` does not exist; controller workflow action does not use a transaction/row lock.

Recommended remediation: add a version column, require the expected version, lock the row in a transaction, and conditionally update as leave/overtime/report workflows do.

### WF-RBAC-005 — Medium — Leave has no safe fallback workflow configuration

`LeaveWorkflowService::normalizeApprovalRules([])` produces empty review/recommend/approve roles. There is no repository seeder for `leave_approval_rules`. A deployment missing the setting can create pending requests with no action owner.

Evidence: configuration audit fails the non-empty review/approve fallback invariant.

Recommended remediation: define safe catalogued defaults and validate that configured stage roles exist and can reach the corresponding management route.

### WF-RBAC-006 — Medium — Report notification detail wrapper drops report routing fields

`toWorkflowNotificationPayload` understands `reportType` and `reportUid`, but `buildWorkflowNotificationDetailPath` does not forward them to `buildWorkflowNotificationDeepLink`. The notification drawer calls the wrapper instead of using the already normalized `deepLink`, so report notifications can fall back to `/reports` rather than the exact report detail.

Recommended remediation: pass `reportType` and `reportUid` through the wrapper or navigate using the normalized `event.deepLink`; add wrapper-level tests for inspection, ERCO, drill, and fitness-test.

### WF-RBAC-007 — Medium — Administrator policy differs between workflow families

Leave, overtime, and payroll treat System Administrator as a role-owner override, while managed reporting still requires the configured workflow role. This produces inconsistent capabilities and frontend expectations.

Recommended remediation: document one policy. The safest common policy is permission/role override with mandatory stage, status, scope/self-action, declaration, and concurrency enforcement.

### WF-RBAC-008 — Low — Leave approval history is unbounded

Overtime and payroll retain 20 history entries and reports retain 30; leave appends without a bound. Correction/edit cycles can grow the JSON column indefinitely.

Recommended remediation: apply a documented retention cap without deleting legally required audit data; if full history is required, move it to an append-only relational audit table.

## Existing browser coverage

The repository already contains browser scenarios for:

- Leave correction and applicant resubmission
- Overtime correction and applicant resubmission
- ERCO, drill, and fitness report review/approval
- Report rejection and unrelated-user denial
- Inspection live CRUD and QA/QC
- Cross-persona API RBAC and notification badge/read persistence

Backend feature tests carry the larger role/stage combination matrix because browser tests are intentionally limited to representative journeys.

## Commands

Run green tests one backend file at a time against the shared PostgreSQL test database. Passing multiple database-refreshing file paths to `php artisan test` can launch conflicting refresh operations against `vmecc_test`.

```text
php artisan test tests/Unit/WorkflowTransitionStateMachineTest.php
php artisan test tests/Unit/WorkflowRoleCatalogMatrixTest.php
php artisan test tests/Feature/LeaveWorkflowRbacTest.php
php artisan test tests/Feature/OvertimeWorkflowRbacMatrixTest.php
php artisan test tests/Feature/PayrollClaimWorkflowRbacTest.php
php artisan test tests/Feature/ReportingWorkflowRoleAssignmentTest.php
```

Run security-invariant audit probes:

```text
php artisan test tests/Audit/WorkflowSecurityInvariantAuditTest.php
php artisan test tests/Audit/WorkflowConfigurationAuditTest.php
```

Run frontend shared workflow coverage:

```text
npx vitest run src/views/staff/shared/__tests__/workflowDomain.test.js src/views/staff/shared/__tests__/workflowContracts.test.js src/components/__tests__/auditHistory.test.js src/services/__tests__/workflowNotificationMapper.test.js src/services/__tests__/workflowNotifications.test.js src/views/notifications/workflow/__tests__/WorkflowNotifications.test.jsx
```

Audit pending ownership after deployment or role changes:

```text
php artisan workflow:audit-rbac
php artisan workflow:audit-rbac --json
```

Reassignment is a dry run unless `--apply` is supplied. Applying requires an approved reason, locks each record, increments its version, appends a `Workflow Reassigned` event, and notifies the replacement role:

```text
php artisan workflow:reassign-role leave "Human Resource"
php artisan workflow:reassign-role leave "Human Resource" --from="Old Role" --reason="Approved change ticket reference" --apply
```

## Remediation boundary

The 2026-07-17 corrective change updates production authorization, workflow transitions, permissions, database schema, settings validation, history retention, and notification routing. Deployment must run the new migrations before the updated application code serves payroll workflow requests.

## Verification result

- Added backend green suites: 40 tests passed, 255 assertions.
- Existing targeted backend workflow suites: 41 tests passed, 381 assertions.
- Frontend workflow cluster: 9 files passed, 40 tests passed.
- Playwright cross-persona API RBAC matrix: 1 test passed across all ten smoke roles.
- Targeted ESLint: passed.
- Targeted Laravel Pint: passed.
- Post-remediation security probes: 9 passed, 18 assertions. The focused frontend workflow cluster and append-only history tests also pass.

The frontend browser server was not active, so live UI browser journeys were not rerun. Existing Playwright browser specifications were inventoried; API transition behavior, component behavior, notification mapping, and role enforcement were verified through PHPUnit, Vitest, and the API-only Playwright matrix.

## Remediation implementation status

Implemented on 2026-07-17. This section supersedes the earlier audit-state statements that the known-gap probes are intentionally red and that production behavior is unchanged.

| Finding | Status | Corrective implementation |
|---|---|---|
| WF-RBAC-001 | Resolved | System Administrator now overrides role ownership only; status, stage order, declarations/remarks, and version checks still run. |
| WF-RBAC-002 | Resolved | `Admin` and `Contract Manager` receive `staff.salary.manage`; the data migration updates existing installations and settings reject roles without the required permission. |
| WF-RBAC-003 | Resolved | Payroll rejection requires current-stage ownership. Staff-side cancellation is restricted to System Administrator and requires remarks. |
| WF-RBAC-004 | Resolved | Payroll claims have a `version`, workflow/payment transitions use transactions and row locks, stale requests return `PAYROLL_CLAIM_VERSION_CONFLICT`, and the frontend sends and refreshes versions. |
| WF-RBAC-005 | Resolved | Leave rules have a non-empty Human Resource fallback; settings validate reachable stage roles. The RBAC audit and controlled reassignment commands detect and repair existing stranded records. |
| WF-RBAC-006 | Resolved | Notification detail routing forwards report type/UID and the drawer uses the normalized deep link. Exact inspection, ERCO, drill, and fitness-test routes are tested. |
| WF-RBAC-007 | Resolved | Reporting uses the same administrator role-override policy while retaining status, order, self-action, scope-policy, and concurrency guards. |
| WF-RBAC-008 | Resolved | Leave JSON history is bounded and all leave/overtime/payroll/report history entries are copied to an append-only relational transition ledger, including migration backfill. |

Operational commands:

```text
php artisan workflow:audit-rbac
php artisan workflow:audit-rbac --json
php artisan workflow:reassign-role leave "Human Resource"
php artisan workflow:reassign-role leave "Human Resource" --apply --reason="Approved recovery reason"
```

`workflow:reassign-role` is a dry run unless `--apply` is supplied. The replacement role must exist, carry the module permission, and have an active assignee. Applied repairs lock each record, append `Workflow Reassigned` history, increment its version, preserve the submission snapshot, write the append-only ledger, and notify the new action owner.

Post-remediation verification:

- Secure-target audit probes: 9 passed, 18 assertions.
- Core workflow RBAC matrices: 23 passed, 167 assertions.
- Payroll payment concurrency suite: 5 passed, 37 assertions.
- Transition ledger and maintenance commands: 4 passed, 18 assertions.
- Frontend workflow cluster: 8 files passed, 43 tests passed.
- Targeted Laravel Pint and ESLint: passed.
