---
key: profile-banking
title: Updating Personal Banking Information
knowledge_type: system_guide
scope_type: module
module_key: profile
route_key: profile
module_gate: profile
required_permissions:
  - self.profile.banking
permission_match: any
allowed_roles: []
version: 3
owner: Human Resources
reviewed_on: 2026-07-17
review_due_on: 2026-10-17
release_status: draft
tags:
  - profile
  - banking
  - system-guide
active: false
---

# Updating Personal Banking Information

## Purpose

Explain how a user reviews and updates only their own payment-destination information.

## Who can access it

Signed-in users with the effective **self.profile.banking** permission.

## Required permission/module state

The Profile module must be enabled. This guide does not grant salary, claim-payment, staff-record, or other-user access.

## Where to find the page

Open **Profile** at /profile and locate **Banking Information**.

## Prerequisites

Confirm the account holder name and account number directly from the intended bank account before editing.

## Exact steps

1. Open /profile, locate **Banking Information**, and select **Edit**.
2. Choose **Bank**, enter **Account name** and **Account number**, and compare all three values with the intended account.
3. Select **Save changes** once, wait for the success response, and confirm the displayed account number remains masked except for its final digits.
4. If a value is wrong, reopen **Edit**, correct only that value, and save again.

## Fields and validation

**Bank**, **Account name**, and **Account number** are optional strings at API level. Bank and account name are limited to 255 characters; account number is limited to 50. The bank control supplies the supported bank list. Banking data is separate from salary rates and payroll workflow settings.

## Statuses and transitions

The section changes between view and edit modes. Values are saved only after the profile API succeeds.

## Who performs the next action

The signed-in user verifies the masked result. Contact Human Resources if the section is unavailable despite the assigned permission.

## Attachments and limits

This section accepts no files. Do not place bank statements, PINs, passwords, or authentication codes in VMECC.

## Common errors and recovery

Correct the field named by validation and retry once. If the wrong account was saved, update it immediately and notify Human Resources through the established channel when a payroll run may be affected.

## What Ask AI cannot do

Ask AI cannot view a full stored account number, edit banking data, verify bank ownership, change salary or payment records, or disclose another user's banking information.

## Related pages

Personal payroll and claims are separate modules. Their visibility does not grant access to this profile section, and this permission does not grant payroll-management actions.

## Source-of-truth code references for maintainers

Frontend: `vmecc-frontend/src/views/profile/Profile.js` and `vmecc-frontend/src/views/profile/BankingSection.js`. Backend: `vmecc-backend/routes/api.php` and the banking validation in `vmecc-backend/app/Http/Controllers/AuthController.php`.

## Guide maintenance

Owner: Human Resources. Candidate version: 3. Reviewed: 2026-07-17. Review due: 2026-10-17. Activation requires a matching hash-bound approval manifest record.
