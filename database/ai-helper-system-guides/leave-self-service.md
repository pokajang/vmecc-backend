---
key: leave-self-service
title: Applying for and Viewing Personal Leave
knowledge_type: system_guide
scope_type: module
module_key: leave
route_key: leave
module_gate: leave.self_service
required_permissions:
  - self.leave
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

# Applying for and Viewing Personal Leave

## Purpose

Apply for personal leave, save a draft, check entitlement and roster impact, and use only applicant actions still allowed by the request.

## Who can access it

Access requires the catalog permission and module gate in frontmatter; ordinary management access remains assignment-scoped.

## Required permission/module state

Both permission and module activation are checked server-side. Route context cannot grant access.

## Where to find the page

Open **Leave** at /leave, **Apply Leave** at /leave/new, or a request at /leave/:leaveId.

## Prerequisites

Verify the exact person, period, record, current state, and effective assignment scope before changing data.

## Exact steps

1. Choose the leave type, start/end dates and time slots; enter the reason and cover-by detail; review calculated days, balance, and roster impact.
2. Upload evidence when needed, save a draft or submit once, then reopen the detail and confirm its status, stage, next role, and version.
3. Edit only Draft or pre-review Pending; delete only Draft; cancel only Pending using the displayed action and current expected version.
4. When **Needs Correction** is returned, amend the named fields and resubmit.

## Fields and validation

Type required/max 100; end date on/after start; shift/slots max 50; reason required/max 2,000; cover-by max 255; days non-negative; expected version at least 1.

## Statuses and transitions

Draft → Pending review → optional recommend → approve. Outcomes: Approved, Rejected, Cancelled, Needs Correction.

## Who performs the next action

The stored next-action role owns the current stage; the applicant waits, cancels Pending, or corrects Needs Correction.

## Attachments and limits

Staged JPG, PNG, WebP, or PDF up to 15 MB; an Approved leave attachment cannot be deleted.

## Common errors and recovery

Reload on version conflict. Missing entitlement and insufficient balance return named validation errors. Editing locks after the first manager action.

## What Ask AI cannot do

Ask AI cannot open records, upload evidence, submit, review, approve, reject, cancel, change settings, bypass validation, or confirm success.

## Related pages

Self-service, management, configuration, and record APIs remain separate permission boundaries.

## Source-of-truth code references for maintainers

`vmecc-frontend/src/views/leave/hooks/useLeaveForm.js`; `vmecc-backend/app/Http/Controllers/LeaveController.php`; `vmecc-backend/app/Http/Controllers/LeaveAttachmentController.php`; `vmecc-backend/app/Services/LeaveWorkflowService.php`.

## Guide maintenance

Owner: Human Resources. Candidate version: 3. Reviewed: 2026-07-17. Review due: 2026-10-17. Activation requires a matching hash-bound approval manifest record.
