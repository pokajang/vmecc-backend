---
key: system-maintenance
title: System Maintenance
knowledge_type: system_guide
scope_type: module
module_key: settings.system_maintenance
route_key: settings
module_gate: settings.system_maintenance
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
  - maintenance
  - operations
  - system-guide
active: true
---
# System Maintenance

## Purpose

Place VMECC into a grace period and then an enforced maintenance lock, or safely return it to normal operation.

## Before you begin

Confirm the maintenance window, user communication, running work, backup and recovery plan, maintenance message, grace duration, and responsible operator.

## Steps

1. Go to **Settings** and open **System Maintenance**.
2. Open **Settings** and confirm the current state and operational approval.
3. Turn maintenance **ON** to enter Grace; verify the banner, message, and countdown.
4. Wait for the grace deadline or select **Enforce now** only under the approved runbook.
5. Perform the planned maintenance work and complete the required health checks.
6. Turn maintenance **OFF**, reload VMECC, and verify that signed-in users can access the system normally.

## What happens next

Off -> Grace -> Enforced. Grace -> Enforced can occur at the deadline or by **Enforce now**. Grace or Enforced -> Off restores normal operation. Each change is audited.

The named maintenance operator enforces and later disables the lock; service owners verify application health before reopening.

## If something goes wrong

Choose **ON** or **OFF** and enter a maintenance message of no more than 500 characters. The page shows whether maintenance is **Off**, in **Grace**, or **Enforced**, together with the relevant times.

Maintenance settings contain no attachments.

If saving fails, the page restores the previous state. Follow the recovery runbook if maintenance settings are unavailable. Always reload the page to confirm whether the lock is active or cleared.

## Related tasks

Use the deployment runbook and application health checks for work performed during the enforced window.
