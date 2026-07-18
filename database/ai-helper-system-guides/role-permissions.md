---
key: role-permissions
title: Role Permissions
knowledge_type: system_guide
scope_type: module
module_key: settings.role_permissions
route_key: settings
module_gate: settings.role_permissions
required_permissions:
  - settings.manage
permission_match: any
allowed_roles: []
version: 3
owner: System Administration
reviewed_on: 2026-07-17
review_due_on: 2026-10-17
release_status: final
tags:
  - settings
  - roles
  - permissions
  - system-guide
active: true
---
# Role Permissions

## Purpose

Control which parts of VMECC each standard role can use without changing the protected **System Administrator** role.

## Before you begin

Prepare the intended access change, affected roles, reason for the change, representative users to test, and a copy of the current selections for rollback.

## Steps

1. Go to **Settings** and open **Role Permissions**.
2. Select **Permission Matrix** or open the required role.
3. Search or filter to the exact role and access; capture the current assignment.
4. Select **Edit**, change only approved access rights, and review the changes-only view.
5. Select **Save** and confirm the updated roles shown in the success message.
6. Reload access rights and test a representative session; restore the captured matrix if the result is wrong.

## What happens next

The selected access is added to or removed from each role. The change takes effect after saving, although affected users may need to sign in again.

System Administration verifies the audit record and affected user behavior after the approved change.

## If something goes wrong

Select only roles and access rights displayed in the matrix. The **System Administrator** role is protected and cannot be changed from this page.

Role access rights have no attachments.

If access does not change immediately, sign out and sign in again. Restore the previous selection if access was granted incorrectly. Role access rights do not skip the review stages of a workflow.

## Related tasks

Use User Administration for role assignments and Dashboard Visibility for the dashboard-only filtered view.
