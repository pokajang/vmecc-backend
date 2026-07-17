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
version: 2
owner: System Administration
reviewed_on: 2026-07-17
review_due_on: 2026-10-17
release_status: draft
tags:
  - settings
  - workflow
  - system-guide
active: false
---

# Dashboard Visibility

## Purpose

Explain the supported VMECC dashboard visibility workflow without exposing records, hidden controls or another permission tier.

## Who can access it

Signed-in users whose effective access satisfies any of settings.manage.

## Required permission/module state

The settings.dashboard_visibility gate must be enabled. The server confirms the listed access rule. Browser page context never grants access.

## Where to find the page

Open /settings or /reporting-settings.

## Prerequisites

Use an active account and the correct organisation or team context. Confirm the intended record, person, date or period before changing anything.

## Exact steps

1. Review the current effective configuration, change only approved supported values, validate dependencies and stage order where shown, save once, then reload and verify the effective result.
2. Wait for a success response and reload or reopen the record before relying on the saved state.
3. Stop when the action is hidden, the gate is disabled or validation identifies a different required step.

## Fields and validation

Only registered settings, modules, roles and permissions are accepted.

## Statuses and transitions

A change is complete only after the API succeeds and the refreshed page shows the new saved or effective state.

## Who performs the next action

The next actor is determined by current state, configured workflow, active assignment scope and effective permissions.

## Attachments and limits

No attachment is required unless the page presents an upload control. Never put credentials or unrelated personal data in notes or files.

## Common errors and recovery

If unavailable, confirm module state and active assignment access. Correct the named validation field and retry once. On a conflict or stale state, reload before acting again. Contact System Administration when access or workflow configuration is wrong.

## What Ask AI cannot do

Ask AI cannot reveal inaccessible instructions or data, open records, click, upload, submit, approve, reject, pay, delete, publish, change settings, bypass validation or confirm success.

## Related pages

Related navigation stays within the settings route family and settings.dashboard_visibility gate; every related page evaluates access independently.

## Source-of-truth code references for maintainers

Audit vmecc-frontend/src/routes.js and the current page component, vmecc-backend/routes/api.php, request validation, permission and module middleware, workflow services and focused tests.

## Guide maintenance

Owner: System Administration. Version: 2. Reviewed: 2026-07-17. Review due: 2026-10-17. Re-audit after route, permission, field, validation, status, attachment or workflow changes.
