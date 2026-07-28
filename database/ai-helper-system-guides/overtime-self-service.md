---
key: overtime-self-service
title: Submitting and Viewing Personal Overtime
knowledge_type: system_guide
scope_type: module
module_key: overtime
route_key: overtime
module_gate: overtime.self_service
required_permissions:
  - self.overtime
permission_match: any
allowed_roles: []
version: 3
owner: Human Resources
reviewed_on: 2026-07-17
review_due_on: 2026-10-17
release_status: final
tags:
  - overtime
  - workflow
  - system-guide
active: true
---
# Submitting and Viewing Personal Overtime

## Purpose

Check eligibility/classification and submit or manage personal overtime.

## Before you begin

Verify the exact person, period, record, current state, and assigned team or area before changing data.

## Steps

1. Go to **Overtime**.
2. Under **Overtime type**, choose an option and select **Continue**.
3. Enter **Date**, **Start time**, **End time**, and **Reason / work done**. If the form says the work ends on the next day, select **I confirm this overtime ends on the next day.**
4. Add an **Evidence attachment (optional)** when needed, then select **Save draft** or **Submit request**.
5. Reopen the request and confirm its status. Use **Edit**, **Delete**, or **Cancel** only when that action is displayed.

## What happens next

**Draft** moves to **Pending Review**, may require a recommendation, and then moves to approval. Other outcomes are **Approved**, **Rejected**, **Needs Correction**, or **Cancelled**.

The stored next role acts; applicant corrects Needs Correction.

## If something goes wrong

Enter the date and times shown on the form, use a duration greater than zero, and provide a reason between 5 and 3,000 characters.

The workflow attachment must exist and be authorized for the claim.

Contact HR for ineligibility. Reload on conflict. Editing locks after the first workflow step.

## Related tasks

Personal overtime, overtime management, rates, and workflow rules are separate areas of the system.
