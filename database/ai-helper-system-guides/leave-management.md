---
key: leave-management
title: Managing Leave Records
knowledge_type: system_guide
scope_type: module
module_key: leave
route_key: leave-management
module_gate: leave.management
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

# Managing Leave Records

## Purpose

Review scoped leave records and perform only the transition owned by the current stage.

## Who can access it

Access requires the catalog permission and module gate in frontmatter; ordinary management access remains assignment-scoped.

## Required permission/module state

Both permission and module activation are checked server-side. Route context cannot grant access.

## Where to find the page

Open /staff/leave-management/leaves and /staff/leave-management/record/:leaveId.

## Prerequisites

Verify the exact person, period, record, current state, and effective assignment scope before changing data.

## Exact steps

1. Open the exact record and verify applicant, dates, balance, evidence, history, stage, next role, and version.
2. Use **Review**, **Recommend**, or **Approve** only at the matching stage; enter required remarks and tick the required declaration.
3. Use **Request correction**, **Reject**, or **Cancel** only when displayed, with required remarks and expected version.
4. Reload and verify status, stage, next actor, history, and incremented version.

## Fields and validation

Remarks max 1,000; reject/correction/cancel require remarks; forward actions require declaration; expected version at least 1.

## Statuses and transitions

Pending advances review → optional recommend → approve → Approved; rejection, correction, or cancellation ends the current path.

## Who performs the next action

The server-stored next role in the employee's scope acts next. Distinct-actor policy may reject a previous actor.

## Attachments and limits

Evidence is read only through the authorized leave attachment endpoint.

## Common errors and recovery

Reload stale records. Role/stage errors name the owner or stage. Administrator override never bypasses status, stage, declaration, distinct actor, or version.

## What Ask AI cannot do

Ask AI cannot open records, upload evidence, submit, review, approve, reject, cancel, change settings, bypass validation, or confirm success.

## Related pages

Self-service, management, configuration, and record APIs remain separate permission boundaries.

## Source-of-truth code references for maintainers

`vmecc-frontend/src/views/staff/leave-management/workflowRecordHelpers.js`; `vmecc-backend/app/Http/Controllers/LeaveWorkflowController.php`; `vmecc-backend/app/Services/LeaveWorkflowService.php`.

## Guide maintenance

Owner: Human Resources. Candidate version: 3. Reviewed: 2026-07-17. Review due: 2026-10-17. Activation requires a matching hash-bound approval manifest record.
