---
key: module-activation
title: Module Activation
knowledge_type: system_guide
scope_type: module
module_key: settings.module_activation
route_key: settings
module_gate: settings.module_activation
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
  - modules
  - activation
  - system-guide
active: true
---
# Module Activation

## Purpose

Enable or disable registered VMECC modules while respecting locked roots, parents, and dependencies.

## Before you begin

Review the feature, its parent and related features, its current state, the affected users, and how you will restore the previous setting if needed.

## Steps

1. Go to **Settings** and open **Module Activation**.
2. Review the page and confirm that no configuration warning is shown.
3. Locate the exact feature and inspect its parent, related features, child features, and current state.
4. Toggle only the intended module; review every child shown as inactive from a parent or dependency.
5. Select **Save**, then reload the page and confirm the selected states remain displayed.
6. Verify affected navigation and system access with representative authorized users.

## What happens next

A feature becomes **Enabled** or **Disabled**. It can also remain unavailable because its parent or a required related feature is disabled. Locked features remain enabled.

The System Administrator checks the affected pages with representative users after saving.

## If something goes wrong

Choose **Enabled** or **Disabled** only for features displayed on the page. A child feature cannot be used while its parent or a required related feature is disabled.

Module activation has no attachments.

Resolve any configuration warning before release. If a child remains unavailable, enable its parent and required related features. To roll back, restore the previous selections and save again.

## Related tasks

Use Role Permissions after activation to confirm who can access the enabled capability.
