---
key: payroll-claims
title: Creating Expense or Salary Claims
knowledge_type: system_guide
scope_type: module
module_key: payroll
route_key: payroll
module_gate: payroll.claims
required_permissions:
  - self.payroll
permission_match: any
allowed_roles: []
version: 2
owner: Finance
reviewed_on: 2026-07-17
review_due_on: 2026-10-17
release_status: draft
tags:
  - payroll
  - workflow
  - system-guide
active: false
---

# Creating Expense or Salary Claims

## Purpose

Explain the supported VMECC creating expense or salary claims workflow without exposing records, hidden controls or another permission tier.

## Who can access it

Signed-in users whose effective access satisfies any of self.payroll.

## Required permission/module state

The payroll.claims gate must be enabled. The server confirms the listed access rule. Browser page context never grants access.

## Where to find the page

Open /payroll.

## Prerequisites

Use an active account and the correct organisation or team context. Confirm the intended record, person, date or period before changing anything.

## Exact steps

1. Open a new Expense or Salary claim, complete the visible date, amount, description and supporting fields, attach required evidence, review totals, then submit.
2. Wait for a success response and reload or reopen the record before relying on the saved state.
3. Stop when the action is hidden, the gate is disabled or validation identifies a different required step.

## Fields and validation

Claim type, date, amount, description, salary period, evidence, payment data, effective dates and overlapping assignments are validated by the relevant API.

## Statuses and transitions

The claim record shows its workflow state. Approval and payment are separate, and payment reversal is audited.

## Who performs the next action

After submission, the next actor is selected by the configured workflow. The applicant monitors the record rather than repeating the action.

## Attachments and limits

Use only the upload control shown. File type, size, count, ownership and retrieval authorization are enforced by the attachment API.

## Common errors and recovery

If unavailable, confirm module state and active assignment access. Correct the named validation field and retry once. On a conflict or stale state, reload before acting again. Contact Finance when access or workflow configuration is wrong.

## What Ask AI cannot do

Ask AI cannot reveal inaccessible instructions or data, open records, click, upload, submit, approve, reject, pay, delete, publish, change settings, bypass validation or confirm success.

## Related pages

Related navigation stays within the payroll route family and payroll.claims gate; every related page evaluates access independently.

## Source-of-truth code references for maintainers

Audit vmecc-frontend/src/routes.js and the current page component, vmecc-backend/routes/api.php, request validation, permission and module middleware, workflow services and focused tests.

## Guide maintenance

Owner: Finance. Version: 2. Reviewed: 2026-07-17. Review due: 2026-10-17. Re-audit after route, permission, field, validation, status, attachment or workflow changes.
