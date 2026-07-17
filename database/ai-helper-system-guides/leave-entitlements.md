---
key: leave-entitlements
title: Leave Entitlements
knowledge_type: system_guide
scope_type: module
module_key: leave
route_key: leave-management
module_gate: leave.assignments
required_permissions:
  - staff.leave.manage
permission_match: any
allowed_roles: []
version: 3
owner: Human Resources
reviewed_on: 2026-07-17
review_due_on: 2026-10-17
release_status: draft
tags:
  - leave
  - workflow
  - system-guide
active: false
---

# Leave Entitlements

## Purpose

Maintain annual employee leave-type assignments used by balance validation.

## Who can access it

Access requires the catalog permission and module gate in frontmatter; ordinary management access remains assignment-scoped.

## Required permission/module state

Both permission and module activation are checked server-side. Route context cannot grant access.

## Where to find the page

Open /staff/leave-management/set-leaves.

## Prerequisites

Verify the exact person, period, record, current state, and effective assignment scope before changing data.

## Exact steps

1. Choose the exact employee, year, registered leave type, and entitlement.
2. Review used, pending, and remaining values; save once. An existing employee/year/type is updated.
3. Edit entitlement, used, or pending using non-negative values and save.
4. Delete only after confirming the effect on current leave requests.

## Fields and validation

Employee must exist; year 2000–2100; type registered; entitlement 0–365; used and pending non-negative.

## Statuses and transitions

Assignments are created, updated, or deleted; balance calculations consume them.

## Who performs the next action

The employee rechecks Leave balance; Human Resources resolves inconsistent consumption.

## Attachments and limits

No attachments.

## Common errors and recovery

Correct unknown employee/type or out-of-range values. Recheck open requests before deletion.

## What Ask AI cannot do

Ask AI cannot open records, upload evidence, submit, review, approve, reject, cancel, change settings, bypass validation, or confirm success.

## Related pages

Self-service, management, configuration, and record APIs remain separate permission boundaries.

## Source-of-truth code references for maintainers

`vmecc-frontend/src/views/staff/leave-management/LeaveManagement.js`; `vmecc-backend/app/Http/Controllers/LeaveAssignmentController.php`; `vmecc-backend/app/Services/LeavePolicyService.php`.

## Guide maintenance

Owner: Human Resources. Candidate version: 3. Reviewed: 2026-07-17. Review due: 2026-10-17. Activation requires a matching hash-bound approval manifest record.
