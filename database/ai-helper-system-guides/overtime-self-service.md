---
key: overtime-self-service
title: Submitting and Viewing Personal Overtime
knowledge_type: system_guide
scope_type: module
module_key: overtime
route_key: overtime
module_gate: overtime.self_service
required_permissions:
  - self.overtime
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

# Submitting and Viewing Personal Overtime

## Purpose

Check eligibility/classification and submit or manage personal overtime.

## Who can access it

Access requires the catalog permission and module gate in frontmatter; ordinary management access remains assignment-scoped.

## Required permission/module state

Both permission and module activation are checked server-side. Route context cannot grant access.

## Where to find the page

Open /overtime, /overtime/new, or /overtime/:overtimeId.

## Prerequisites

Verify the exact person, period, record, current state, and effective assignment scope before changing data.

## Exact steps

1. Check eligibility; choose claim date and verify weekday, weekend, or public-holiday classification.
2. Enter start/end time, Overnight when needed, positive duration, and a 5–3,000 character reason; select evidence when required.
3. Save or submit once; reopen and confirm status, stage, next role, and version.
4. Edit only Draft or pre-review Pending; delete Draft/Cancelled; cancel Pending/Approved with current version.

## Fields and validation

Type weekday/weekend/publicHoliday; date required; HH:mm times; duration positive; reason 5–3,000; valid owned attachment; expected version at least 1.

## Statuses and transitions

Draft → Pending review → optional recommend → approve; outcomes Approved, Rejected, Needs Correction, Cancelled.

## Who performs the next action

The stored next role acts; applicant corrects Needs Correction.

## Attachments and limits

The workflow attachment must exist and be authorized for the claim.

## Common errors and recovery

Contact HR for ineligibility. Reload on conflict. Editing locks after the first workflow step.

## What Ask AI cannot do

Ask AI cannot open records, upload evidence, submit, review, approve, reject, cancel, change settings, bypass validation, or confirm success.

## Related pages

Self-service, management, configuration, and record APIs remain separate permission boundaries.

## Source-of-truth code references for maintainers

`vmecc-frontend/src/views/overtime/hooks/useOvertimeForm.js`; `vmecc-frontend/src/views/overtime/domain/overtimeFormDomain.js`; `vmecc-backend/app/Http/Controllers/OvertimeController.php`; `vmecc-backend/app/Services/OvertimeWorkflowService.php`.

## Guide maintenance

Owner: Human Resources. Candidate version: 3. Reviewed: 2026-07-17. Review due: 2026-10-17. Activation requires a matching hash-bound approval manifest record.
