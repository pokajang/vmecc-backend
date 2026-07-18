---
key: workflow-notifications-settings
title: Workflow Notifications
knowledge_type: system_guide
scope_type: module
module_key: workflow_notifications
route_key: settings
module_gate: workflow_notifications
required_permissions: []
permission_match: any
allowed_roles: []
version: 3
owner: System Administration
reviewed_on: 2026-07-17
review_due_on: 2026-10-17
release_status: final
tags:
  - workflow-notifications
  - inbox
  - actions
  - system-guide
active: true
---
# Workflow Notifications

## Purpose

Review workflow notices visible to the signed-in user, open the linked record, and manage personal read or dismissed state.

## Before you begin

Identify whether the notice is informational or requires action, then confirm the VMECC area, record number, current status, and intended link.

## Steps

1. Go to **Workflow Notifications**.
2. Open the header notifications panel or **Workflow Notifications** page.
3. Filter or group the visible notices and open the intended item.
4. Follow its link and verify the record number before performing any action on the record.
5. Mark one notice or all visible notices read after review.
6. Dismiss one or all notices only to hide them from your view; reload to verify personal state.

## What happens next

Personal state is unread -> read and visible -> dismissed. Workflow resolution is separate and can mark action-required notices resolved without changing the business record from this page.

The recipient follows the notification link; the target workflow decides whether that user can act next.

## If something goes wrong

The list accepts unread and action filters plus a bounded limit. Mark-read and dismiss actions operate only on a notification visible to the current user.

Notifications do not upload attachments. Linked workflows own their evidence controls and limits.

A missing notice may already be dismissed or resolved, or it may no longer apply to your assignment. If the linked record shows **Access denied**, return to the notice list and ask an administrator to check your assignment; the notice itself does not grant access.

## Related tasks

The link resolver sends each notice to its authorized Leave, Overtime, Payroll, Report, Inspection, Team, or other workflow page.
