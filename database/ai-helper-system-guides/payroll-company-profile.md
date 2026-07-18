---
key: payroll-company-profile
title: Configuring Company Information for Payroll
knowledge_type: system_guide
scope_type: module
module_key: payroll
route_key: salary-claims
module_gate: payroll.company_profile
required_permissions:
  - settings.manage
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
  - company
  - settings
  - system-guide
active: true
---
# Configuring Company Information for Payroll

## Purpose

Maintain the legal and Finance contact details used by supported payroll outputs.

## Before you begin

Verify legal name, company registration number, Malaysian tax number, address, general contact, and Finance contact details against an approved company source.

## Steps

1. Go to **Payroll Configuration** and open **Company Information**.
2. In **Company Information**, compare every displayed value with the approved legal/company record.
3. Update the required business values and any supported email, phone, and Finance contact fields.
4. Select **Save** once, then verify the confirmation message, last-updated details, and new history entry.

## What happens next

The latest successful save becomes current configuration. The system retains up to 100 profile history entries and records the updater and timestamp.

Finance verifies generated payroll outputs after a profile change. Correct company data before issuing affected documents.

## If something goes wrong

Legal name and Finance contact name allow 255 characters; registration and tax numbers allow 100; address allows 500; phone fields allow 50; email fields must be valid email addresses and allow 255 characters. Fields can be left blank, but missing company details may affect payroll documents.

No logo or document upload is handled by this profile action. Do not enter credentials, bank secrets, or personal identification documents.

Correct any email address or field length named in the message. If the page cannot save, leave the current values unchanged and report the error to System Administration. Reload before overwriting a change made by another authorized user.

## Related tasks

Statutory rates, salary assignments, claims, payments, and workflow rules are separate controls.
