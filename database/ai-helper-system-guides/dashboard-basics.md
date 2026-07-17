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
release_status: draft
tags:
  - dashboard
  - navigation
  - system-guide
active: false
---

# Using the Dashboard

## Purpose

Explain the read-only dashboard summary, permission-filtered cards, action queue, and navigation to the underlying VMECC records.

## Who can access it

Signed-in users with the effective **self.dashboard** permission.

## Required permission/module state

The Dashboard module and the card's own module must be enabled. Payroll, overtime, leave, roster, and report cards also require their corresponding dashboard view permission.

## Where to find the page

Open **Dashboard** at /dashboard.

## Prerequisites

Use the active assignment context intended for the work. Dashboard figures are summaries; verify a record on its source page before acting on it.

## Exact steps

1. Open **Dashboard** and wait for the header, action queue, and permitted summary cards to finish loading.
2. Review only the cards shown for Payroll, Overtime, Leave, Roster, or Reports; a missing card means its module or dashboard permission is not effective for the current user.
3. Select an enabled card or action-queue item to open the linked module record, then verify the current detail and available action on that page.
4. Refresh the dashboard after completing an action elsewhere to load the latest server summary.

## Fields and validation

Dashboard cards are read-only. The server filters each statistics endpoint by **self.dashboard**, the card-specific permission, module activation, and assignment scope. Action-queue links are derived from records already visible to the user.

## Statuses and transitions

Loading, empty, data, and error states are shown independently for dashboard sections. No workflow status changes on the dashboard itself.

## Who performs the next action

The user opens the underlying leave, overtime, payroll, roster, or report page. That page rechecks authorization and owns the action.

## Attachments and limits

The dashboard has no upload control and does not provide attachment management.

## Common errors and recovery

If all dashboard data is unavailable, reload once and verify the session. If one card is absent, contact System Administration to check its module and permission; page context cannot add it. If a linked record is no longer available, return to the dashboard and refresh the action queue.

## What Ask AI cannot do

Ask AI cannot expose hidden cards, change dashboard totals, open queue records, grant dashboard permissions, or complete an action-queue item.

## Related pages

Card links lead to the relevant Payroll, Overtime, Leave, Roster, or Reports page. Each destination enforces its own module and permission requirements.

## Source-of-truth code references for maintainers

Frontend: `vmecc-frontend/src/views/dashboard/Dashboard.js`, `vmecc-frontend/src/views/dashboard/hooks/useDashboardStats.js`, and `vmecc-frontend/src/views/dashboard/hooks/useDashboardActionQueue.js`. Backend: `vmecc-backend/routes/api.php`, `vmecc-backend/app/Http/Controllers/DashboardController.php`, and `vmecc-backend/app/Http/Controllers/ActionQueueController.php`.

## Guide maintenance

Owner: System Administration. Candidate version: 3. Reviewed: 2026-07-17. Review due: 2026-10-17. Activation requires a matching hash-bound approval manifest record.
