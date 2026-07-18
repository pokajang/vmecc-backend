---
key: inspection-issue-management
title: Inspection Issue Management
knowledge_type: system_guide
scope_type: module
module_key: reports.inspection
route_key: inspection
module_gate: reports.inspection
required_permissions:
  - reports.inspection.issues.manage
permission_match: any
allowed_roles: []
version: 3
owner: Operations
reviewed_on: 2026-07-17
review_due_on: 2026-10-17
release_status: final
tags:
  - inspection
  - issues
  - corrective-action
  - system-guide
active: true
---
# Inspection Issue Management

## Purpose

Triage, assign, start, resolve, reopen, or cancel fire-extinguisher defects with auditable protection against overlapping changes.

## Before you begin

Verify the asset, check criterion, severity, occurrence history, current assignee, due date, status, evidence, and latest saved details.

## Steps

1. Go to **Inspection** and open **Issues**.
2. Open the issue and confirm its asset identity, title, description, severity, status, and history.
3. Assign an active user and add an assignment note, then start an Open issue.
4. Enter corrective action and resolution notes; attach resolution evidence and resolve the issue.
5. After verification, reopen only with a reason and only while its asset is active; cancel an active issue only with a reason.
6. Reload after every action and verify status, event history, assignee, evidence, and updated history.

## What happens next

Open -> In progress -> Pending verification. Verification closes the issue. Closed or Cancelled -> Open can reopen; Open, In progress, or Pending verification -> Cancelled can cancel.

The assigned manager performs corrective work; a user with the separate verification access closes a pending-verification issue.

## If something goes wrong

Title is at most 255 characters; description and assignment note are at most 5,000; severity is low, medium, high, or critical. Corrective action and resolution notes are required and at most 10,000. Resolution accepts at most 10 media items. Every write requires the latest saved details.

Resolution evidence accepts up to 10 report attachment references. Upload and link the evidence before resolving.

If another user changed the issue, reload it. An issue for a retired extinguisher cannot be reopened. If an action is unavailable for the current status, return to the issue and use only the action shown.

## Related tasks

Use the extinguisher catalog for asset lifecycle and the verification guide for independent closure.
