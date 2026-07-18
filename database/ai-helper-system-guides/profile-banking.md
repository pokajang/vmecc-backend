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
release_status: final
tags:
  - profile
  - banking
  - system-guide
active: true
---
# Updating Personal Banking Information

## Purpose

Explain how a user reviews and updates only their own payment-destination information.

## Before you begin

Confirm the account holder name and account number directly from the intended bank account before editing.

## Steps

1. Go to **Profile** and find **Banking Info**.
2. In **Banking Info**, select **Edit**.
3. Choose **Bank**, enter **Account name** and **Account number**, and compare all three values with the intended account.
4. Select **Save changes** once, wait for the confirmation message, and confirm the displayed account number remains masked except for its final digits.
5. If a value is wrong, reopen **Edit**, correct only that value, and save again.

## What happens next

The section returns to view mode after the banking information is saved successfully.

The signed-in user verifies the masked result. Contact Human Resources if the section is unavailable despite the assigned access.

## If something goes wrong

**Bank**, **Account name**, and **Account number** can be left blank. Bank and account name are limited to 255 characters; account number is limited to 50. Choose a bank from the list shown on the form.

This section accepts no files. Do not place bank statements, PINs, passwords, or authentication codes in VMECC.

Correct the field named by validation and retry once. If the wrong account was saved, update it immediately and notify Human Resources through the established channel when a payroll run may be affected.

## Related tasks

Personal payroll and claims are separate modules. Their visibility does not grant access to this profile section, and this access does not grant payroll-management actions.
