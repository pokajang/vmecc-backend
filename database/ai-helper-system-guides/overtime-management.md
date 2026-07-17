---
key: overtime-management
title: Managing Overtime Records
knowledge_type: system_guide
scope_type: module
module_key: overtime
route_key: overtime-management
module_gate: overtime.management
required_permissions:
  - staff.overtime.manage
permission_match: any
allowed_roles: []
version: 3
owner: Human Resources
reviewed_on: 2026-07-17
review_due_on: 2026-10-17
release_status: draft
tags:
  - overtime
  - workflow
  - system-guide
active: false
---

# Managing Overtime Records

## Purpose

Process scoped overtime at its exact current workflow stage.

## Who can access it

Access requires the catalog permission and module gate in frontmatter; ordinary management access remains assignment-scoped.

## Required permission/module state

Both permission and module activation are checked server-side. Route context cannot grant access.

## Where to find the page

Open /staff/overtime-management/records and /staff/overtime-management/record/:overtimeRouteKey.

## Prerequisites

Verify the exact person, period, record, current state, and effective assignment scope before changing data.

## Exact steps

1. Open the record and verify employee, time, type, evidence, history, stage, next role, and version.
2. Use **Review**, **Recommend**, or **Approve** only at the matching stage.
3. Use **Request correction**, **Reject**, or **Cancel** only when displayed; correction requires remarks.
4. Reload and verify status, next role, history, and incremented version.

## Fields and validation

Remarks max 1,000; expected version at least 1; server checks scope, stage role, and distinct actor.

## Statuses and transitions

Pending advances review → optional recommend → approve → Approved; correction, rejection, and cancellation stop the path.

## Who performs the next action

The configured next role in employee scope acts next.

## Attachments and limits

Evidence uses authorized workflow attachment handling.

## Common errors and recovery

Reload conflicts; obey named role/stage errors. Administrator access does not bypass workflow invariants.

## What Ask AI cannot do

Ask AI cannot open records, upload evidence, submit, review, approve, reject, cancel, change settings, bypass validation, or confirm success.

## Related pages

Self-service, management, configuration, and record APIs remain separate permission boundaries.

## Source-of-truth code references for maintainers

`vmecc-frontend/src/views/staff/overtime-management/OvertimeManagement.js`; `vmecc-backend/app/Http/Controllers/OvertimeWorkflowController.php`; `vmecc-backend/app/Services/OvertimeWorkflowService.php`.

## Guide maintenance

Owner: Human Resources. Candidate version: 3. Reviewed: 2026-07-17. Review due: 2026-10-17. Activation requires a matching hash-bound approval manifest record.
