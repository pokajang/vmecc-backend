---
key: report-management
title: Report Management Actions
knowledge_type: system_guide
scope_type: module
module_key: reports
route_key: reports
module_gate: reports
required_permissions:
  - reports.manage
permission_match: any
allowed_roles: []
version: 2
owner: Operations
reviewed_on: 2026-07-17
review_due_on: 2026-10-17
release_status: draft
tags:
  - reports
  - workflow
  - system-guide
active: false
---

# Report Management Actions

## Purpose

Explain the supported VMECC report management actions workflow without exposing records, hidden controls or another permission tier.

## Who can access it

Signed-in users whose effective access satisfies any of reports.manage.

## Required permission/module state

The reports gate must be enabled. The server confirms the listed access rule. Browser page context never grants access.

## Where to find the page

Open /report.

## Prerequisites

Use an active account and the correct organisation or team context. Confirm the intended record, person, date or period before changing anything.

## Exact steps

1. Open the intended record or New page, complete only the sections and findings shown, save supported draft work, attach media within displayed limits, and use only the workflow action offered for the current state.
2. Wait for a success response and reload or reopen the record before relying on the saved state.
3. Stop when the action is hidden, the gate is disabled or validation identifies a different required step.

## Fields and validation

Report type, team scope, dates, sections, media, findings, issue state, workflow action and export eligibility are validated by each report API.

## Statuses and transitions

Draft, submission, review, verification and resolution states depend on report type and configured workflow; follow only displayed transitions.

## Who performs the next action

The next actor is determined by current state, configured workflow, active assignment scope and effective permissions.

## Attachments and limits

Use only the upload control shown. File type, size, count, ownership and retrieval authorization are enforced by the attachment API.

## Common errors and recovery

If unavailable, confirm module state and active assignment access. Correct the named validation field and retry once. On a conflict or stale state, reload before acting again. Contact Operations when access or workflow configuration is wrong.

## What Ask AI cannot do

Ask AI cannot reveal inaccessible instructions or data, open records, click, upload, submit, approve, reject, pay, delete, publish, change settings, bypass validation or confirm success.

## Related pages

Related navigation stays within the reports route family and reports gate; every related page evaluates access independently.

## Source-of-truth code references for maintainers

Audit vmecc-frontend/src/routes.js and the current page component, vmecc-backend/routes/api.php, request validation, permission and module middleware, workflow services and focused tests.

## Guide maintenance

Owner: Operations. Version: 2. Reviewed: 2026-07-17. Review due: 2026-10-17. Re-audit after route, permission, field, validation, status, attachment or workflow changes.
