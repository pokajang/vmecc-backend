---
key: payroll-claims
title: Creating and Managing Personal Claims
knowledge_type: system_guide
scope_type: module
module_key: payroll
route_key: payroll
module_gate: payroll.claims
required_permissions:
  - self.payroll
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
  - self-service
  - system-guide
active: true
---
# Creating and Managing Personal Claims

## Purpose

Create, save, submit, review, correct, or cancel the signed-in user's supported expense and salary claim records.

## Before you begin

Confirm the claim type, claim date or salary period, amount, business reason, and required evidence. A salary claim may depend on the salary assignment and approved overtime saved for that period.

## Steps

1. Go to **Payroll** and open **Claims**.
2. Select **Claims**, choose the claim type, and complete the visible date/period, amount, description, and type-specific fields.
3. Add only evidence owned by the signed-in user, then use **Save draft** to continue later or review the declaration and submit the claim.
4. Open the saved record to verify its system status and history; edit only while the system reports it editable.
5. For **Needs Correction**, change the requested fields and resubmit; use **Cancel claim** only when that action is shown.

## What happens next

Drafts are separate from submitted records. A submitted record enters **Pending** at the configured check stage, may progress through review and approve, and can finish as **Approved**, **Rejected**, **Cancelled**, or **Needs Correction**. Payment is a later Finance-only action.

The claim's stored **Current Action Owner** identifies the active assigned role for the current stage. The applicant acts again only after a correction request or when a permitted cancel/edit action is shown.

## If something goes wrong

Enter a valid claim type, date or salary period, amount, description, and any type-specific salary details. Use only your own evidence and avoid submitting a duplicate or overlapping claim. A message identifies any field that needs correction.

Add evidence through the claim's **Attachments** control. The file must belong to the applicant; deleting your local draft never gives access to another user's file.

Correct the fields named in the message. If the claim changed while you were viewing it, reload it. If an action is locked after review begins, wait for the assigned reviewer or use the displayed correction or cancel action. Contact the payroll administrator when a salary assignment or review role is missing.

## Related tasks

Use **Payslips** for issued pay records. Claim review, payment recording, and salary settings are separate tasks for authorized users.
