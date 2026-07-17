---
key: overtime-rates
title: Overtime Rules and Rates
knowledge_type: system_guide
scope_type: module
module_key: overtime
route_key: overtime-management
module_gate: overtime.rate_settings
required_permissions:
  - settings.manage
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

# Overtime Rules and Rates

## Purpose

Configure multipliers, eligible roles, and normal-hour calculation inputs for new overtime.

## Who can access it

Access requires the catalog permission and module gate in frontmatter; ordinary management access remains assignment-scoped.

## Required permission/module state

Both permission and module activation are checked server-side. Route context cannot grant access.

## Where to find the page

Open /staff/overtime-management and select rate settings.

## Prerequisites

Verify the exact person, period, record, current state, and effective assignment scope before changing data.

## Exact steps

1. Review weekday, weekend, and public-holiday multipliers and eligible roles.
2. Choose base-hour mode and normal-hours strategy; enter the divisor/global/default/role hours used by that strategy.
3. Save once and reload to verify normalized effective values.
4. Create a controlled new draft and verify classification/calculation without rewriting historical claims.

## Fields and validation

Multipliers 0–100; divisor 0–366; hours/day 0–24; mode/strategy registered; role names exist.

## Statuses and transitions

Settings are normalized and audited; new calculations use current values.

## Who performs the next action

HR or Finance validates the controlled calculation.

## Attachments and limits

No attachments.

## Common errors and recovery

Correct unknown roles/modes or out-of-range numbers; reload before testing.

## What Ask AI cannot do

Ask AI cannot open records, upload evidence, submit, review, approve, reject, cancel, change settings, bypass validation, or confirm success.

## Related pages

Self-service, management, configuration, and record APIs remain separate permission boundaries.

## Source-of-truth code references for maintainers

`vmecc-frontend/src/views/staff/overtime-management/OvertimeManagement.js`; `vmecc-backend/app/Http/Controllers/SettingsController.php`; `vmecc-backend/app/Services/OvertimeWorkflowService.php`.

## Guide maintenance

Owner: Human Resources. Candidate version: 3. Reviewed: 2026-07-17. Review due: 2026-10-17. Activation requires a matching hash-bound approval manifest record.
