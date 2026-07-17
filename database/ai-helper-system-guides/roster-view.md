---
key: roster-view
title: Viewing Roster Information
knowledge_type: system_guide
scope_type: module
module_key: roster
route_key: roster
module_gate: roster
required_permissions:
  - teams.view
permission_match: any
allowed_roles: []
version: 2
owner: Operations
reviewed_on: 2026-07-17
review_due_on: 2026-10-17
release_status: draft
tags:
  - roster
  - workflow
  - system-guide
active: false
---

# Viewing Roster Information

## Purpose

Explain the supported VMECC viewing roster information workflow without exposing records, hidden controls or another permission tier.

## Who can access it

Signed-in users whose effective access satisfies any of teams.view.

## Required permission/module state

The roster gate must be enabled. The server confirms the listed access rule. Browser page context never grants access.

## Where to find the page

Open /roster.

## Prerequisites

Use an active account and the correct organisation or team context. Confirm the intended record, person, date or period before changing anything.

## Exact steps

1. Open the stated page, select the intended record or section, complete only visible supported fields, review the summary and current state, then use the enabled primary action once.
2. Wait for a success response and reload or reopen the record before relying on the saved state.
3. Stop when the action is hidden, the gate is disabled or validation identifies a different required step.

## Fields and validation

Team, period, date, shift, member, overlap and publication rules are validated before save or publish.

## Statuses and transitions

Roster data is draft or published. Viewers should rely only on the published period.

## Who performs the next action

The next actor is determined by current state, configured workflow, active assignment scope and effective permissions.

## Attachments and limits

No attachment is required unless the page presents an upload control. Never put credentials or unrelated personal data in notes or files.

## Common errors and recovery

If unavailable, confirm module state and active assignment access. Correct the named validation field and retry once. On a conflict or stale state, reload before acting again. Contact Operations when access or workflow configuration is wrong.

## What Ask AI cannot do

Ask AI cannot reveal inaccessible instructions or data, open records, click, upload, submit, approve, reject, pay, delete, publish, change settings, bypass validation or confirm success.

## Related pages

Related navigation stays within the roster route family and roster gate; every related page evaluates access independently.

## Source-of-truth code references for maintainers

Audit vmecc-frontend/src/routes.js and the current page component, vmecc-backend/routes/api.php, request validation, permission and module middleware, workflow services and focused tests.

## Guide maintenance

Owner: Operations. Version: 2. Reviewed: 2026-07-17. Review due: 2026-10-17. Re-audit after route, permission, field, validation, status, attachment or workflow changes.
