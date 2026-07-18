---
key: dashboard-visibility
title: Dashboard Visibility
knowledge_type: system_guide
scope_type: module
module_key: settings.dashboard_visibility
route_key: settings
module_gate: settings.dashboard_visibility
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
  - dashboard
  - visibility
  - system-guide
active: true
---
# Dashboard Visibility

## Purpose

Control which Dashboard sections each role can see.

## Before you begin

Identify the role, dashboard section, approved visibility change, underlying data access implications, and rollback state.

## Steps

1. Go to **Settings** and open **Dashboard Visibility**.
2. Choose **Role editor** or **Matrix**.
3. Select the exact role and review its current Dashboard access.
4. Select **Edit**, toggle only approved dashboard sections, and review the changed items.
5. Save, confirm the changed roles, and allow the page to refresh the current session.
6. Sign in with a representative affected account and verify both Dashboard visibility and access to the linked records.

## What happens next

The selected Dashboard sections become visible or hidden after the affected user refreshes their session. This change does not give access to records that the role cannot already open.

System Administration validates the affected dashboard and corresponding system access after save.

## If something goes wrong

Select only the roles and Dashboard sections displayed on the page. The **System Administrator** role remains protected and cannot be changed here.

Dashboard visibility has no attachments.

If no rows appear, confirm the dashboard access rights exist in the catalog. If a section remains unavailable, verify its module and data access rights. Restore the prior matrix to roll back.

## Related tasks

Use **Role Permissions** for the full access matrix and **Module Activation** to enable or disable features.
