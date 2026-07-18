---
key: dashboard-basics
title: Using the Dashboard
knowledge_type: system_guide
scope_type: module
module_key: dashboard
route_key: dashboard
module_gate: dashboard
required_permissions:
  - self.dashboard
permission_match: any
allowed_roles: []
version: 3
owner: System Administration
reviewed_on: 2026-07-17
review_due_on: 2026-10-17
release_status: final
tags:
  - dashboard
  - navigation
  - system-guide
active: true
---
# Using the Dashboard

## Purpose

Explain the read-only dashboard summary, access-filtered cards, action queue, and navigation to the underlying VMECC records.

## Before you begin

Use the active assignment context intended for the work. Dashboard figures are summaries; verify a record on its source page before acting on it.

## Steps

1. Go to **Dashboard**.
2. Open **Dashboard** and wait for the header, action queue, and permitted summary cards to finish loading.
3. Review only the cards shown for Payroll, Overtime, Leave, Roster, or Reports; a missing card means its module or dashboard access is not effective for the current user.
4. Select an enabled card or action-queue item to open the linked module record, then verify the current detail and available action on that page.
5. Refresh the dashboard after completing an action elsewhere to load the latest summary.

## What happens next

Loading, empty, data, and error states are shown independently for dashboard sections. No workflow status changes on the dashboard itself.

The user opens the linked leave, overtime, payroll, roster, or report page. The linked page checks access again and shows any action the user can perform.

## If something goes wrong

Dashboard cards are read-only. Cards appear only when your role, assigned team, and enabled modules allow you to view them. Action links open records already available to you.

The dashboard has no upload control and does not provide attachment management.

If all dashboard data is unavailable, reload once and verify the session. If one card is absent, contact System Administration to check its module and access; page context cannot add it. If a linked record is no longer available, return to the dashboard and refresh the action queue.

## Related tasks

Card links lead to the relevant Payroll, Overtime, Leave, Roster, or Reports page. Each destination enforces its own module and access requirements.
