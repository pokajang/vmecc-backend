---
key: salary-claims-management
title: Reviewing Salary and Expense Claims
knowledge_type: system_guide
scope_type: module
module_key: payroll
route_key: salary-claims
module_gate: payroll.salary_claims_management
required_permissions:
  - staff.salary.manage
permission_match: any
allowed_roles: []
version: 3
owner: Finance
reviewed_on: 2026-07-17
review_due_on: 2026-10-17
release_status: final
tags:
  - payroll
  - claims
  - management
  - workflow
  - system-guide
active: true
---
# Reviewing Salary and Expense Claims

## Purpose

Review employee claims for your assigned team and perform only the check, review, approval, rejection, or cancellation action shown for the current stage.

## Before you begin

Verify the claimant, claim type, amount, date or period, evidence, saved salary and overtime details, **Current Status**, **Current Action Owner**, **Next Action**, and history.

## Steps

1. Go to **Payroll Records** and open **Claim Records**.
2. Open the claim detail and compare the submitted fields and evidence with the calculated totals and workflow history.
3. Use **Check**, **Review**, or **Approve** only when it matches the current stage and the record names an active role you hold.
4. Use **Reject** or **Cancel** only when displayed, enter the required remarks, and submit the latest details shown on the page.
5. Reload the record and verify its new status, stage, next actor, history entry, and updated history.

## What happens next

**Pending** begins at check, advances to review, then approve, and finishes **Approved**. **Rejected** and **Cancelled** finish the path. A claim cannot be paid before approval.

The active assignment for the claim's **Current Action Owner** performs the **Next Action**. System Administrator access does not bypass status, stage, distinct-actor, or latest-details checks.

## If something goes wrong

Workflow remarks can contain up to 1,000 characters; **Reject** and **Cancel** require remarks. If the claim changed while you were viewing it, reload before trying again. An action is unavailable when the claimant, assigned team, stage, role, or current status does not match the required workflow step.

Evidence is read through authorized claim attachment links. Management access to a claim does not make the underlying file public.

If another user changed the claim, reload it. A stage or role message identifies why the action is unavailable. If no active user holds the next role, correct role assignments or workflow settings before retrying; do not skip the stage.

## Related tasks

Personal claim submission, payment actions, salary assignments, and workflow settings use separate areas of the system.
