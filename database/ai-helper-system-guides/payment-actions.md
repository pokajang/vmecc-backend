---
key: payment-actions
title: Recording Claim Payments
knowledge_type: system_guide
scope_type: module
module_key: payroll
route_key: salary-claims
module_gate: payroll.payment_actions
required_permissions:
  - staff.salary.pay
permission_match: any
allowed_roles: []
version: 3
owner: Finance
reviewed_on: 2026-07-17
review_due_on: 2026-10-17
release_status: final
tags:
  - payroll
  - payment
  - finance
  - system-guide
active: true
---
# Recording Claim Payments

## Purpose

Record or reverse payment state for approved claims without changing the underlying approval workflow.

## Before you begin

The claim must be **Approved**. Verify claimant, amount, approval history, current paid state, latest details, payment date, and external payment reference before recording payment.

## Steps

1. Go to **Payroll Records** and open **Salary Records** or **Claim Records**.
2. Open one approved claim or select up to 200 eligible approved claims and choose **Mark paid**.
3. Enter the required payment date and optional payment reference up to 255 characters and payment note up to 2,000 characters, then confirm.
4. Reload and verify **Paid**, the payment date, reference, person who recorded it, and history entry.
5. To reverse payment, choose **Unmark paid**, enter a required reason of up to 1,000 characters, confirm, and verify that the claim is shown as unpaid.

## What happens next

Payment does not replace claim status: only an **Approved** claim can change from unpaid to paid. Reversal changes paid to unpaid and preserves an audit record. Repeating the same transition is rejected.

Finance verifies reconciliation after payment. If a claim itself is wrong, return to the claim workflow; payment access cannot rewrite an approved claim.

## If something goes wrong

A payment date is required. A reference can contain up to 255 characters and a note up to 2,000. You can mark up to 200 eligible claims paid at once. A reversal requires a reason. Claims that changed, are repeated, or are not eligible are skipped and listed in the result.

Payment actions do not upload evidence. Existing claim evidence remains visible only to users who can open the claim.

Remove ineligible bulk rows, reload out-of-date information, and retry only failed rows. Do not mark a pending, rejected, cancelled, or already-paid claim. Investigate reported skipped entries individually.

## Related tasks

Claim review, payment recording, salary assignments, and payroll settings are separate tasks and may be available to different users.
