---
key: holiday-administration
title: Holiday Administration
knowledge_type: system_guide
scope_type: module
module_key: leave
route_key: leave-management
module_gate: leave.holidays
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

# Holiday Administration

## Purpose

Maintain national and additional holidays used by leave-day calculation.

## Who can access it

Access requires the catalog permission and module gate in frontmatter; ordinary management access remains assignment-scoped.

## Required permission/module state

Both permission and module activation are checked server-side. Route context cannot grant access.

## Where to find the page

Open /staff/leave-management/set-holidays.

## Prerequisites

Verify the exact person, period, record, current state, and effective assignment scope before changing data.

## Exact steps

1. Choose the year and review national and additional holidays separately.
2. For national rows retain the fixed key, edit name/date/applicable, and save the batch.
3. For an additional holiday enter name/date, choose scope and state when required, and save.
4. Edit or delete only after checking affected leave calculations.

## Fields and validation

National key max 100; names max 255; date YYYY-MM-DD; applicable boolean; additional scope registered; state nullable/max 100.

## Statuses and transitions

National rows upsert by fixed key/year; additional rows create/edit/delete and changes are audited.

## Who performs the next action

Human Resources verifies affected leave calculations.

## Attachments and limits

No attachments.

## Common errors and recovery

Correct invalid dates, scope, or text. Reload the year after a batch save.

## What Ask AI cannot do

Ask AI cannot open records, upload evidence, submit, review, approve, reject, cancel, change settings, bypass validation, or confirm success.

## Related pages

Self-service, management, configuration, and record APIs remain separate permission boundaries.

## Source-of-truth code references for maintainers

`vmecc-frontend/src/views/staff/leave-management/LeaveManagement.js`; `vmecc-backend/app/Http/Controllers/HolidayController.php`; `vmecc-backend/app/Http/Controllers/LeaveController.php`.

## Guide maintenance

Owner: Human Resources. Candidate version: 3. Reviewed: 2026-07-17. Review due: 2026-10-17. Activation requires a matching hash-bound approval manifest record.
