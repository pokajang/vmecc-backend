---
key: report-management
title: Report Management Actions
knowledge_type: system_guide
scope_type: module
module_key: reports
route_key: reports
module_gate: reports
required_permissions:
  - reports.manage
permission_match: any
allowed_roles: []
version: 3
owner: Operations
reviewed_on: 2026-07-17
review_due_on: 2026-10-17
release_status: final
tags:
  - reports
  - workflow
  - management
  - system-guide
active: true
---
# Report Management Actions

## Purpose

Manage report drafts and complete the review actions available for each report type.

## Before you begin

Confirm the report type, owner, assigned team or location, current status, and action shown on the page.

## Steps

1. Go to **Reports**.
2. Open the selected report list and filter by status, assigned team, or location.
3. Open the record and verify its content, timeline, current status, and latest details.
4. Select **Review**, **Approve**, or **Reject** only when shown; enter rejection remarks.
5. Confirm the action and reload the record.
6. Verify the new status, updated history, actor, timestamp, and timeline entry.

## What happens next

**Submitted** moves to **Reviewed** and then **Approved**. A report may be **Rejected** while submitted or reviewed.

The assigned review and approval roles determine who acts next. Each report type follows its saved rules for self-review and self-approval.

## If something goes wrong

Remarks are limited to 2,000 characters and are required when rejecting a report.

Review actions do not add attachments. Add evidence through the report form before submission.

If another user changed the report, reload it before acting again. Correct any field named in a validation message. If the record is missing or access is denied, ask an administrator to check your assignment and report access.

## Related tasks

See the guide for the selected report type. Use **Reporting Settings** to manage its review sequence.
