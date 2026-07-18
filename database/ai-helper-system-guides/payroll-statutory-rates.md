---
key: payroll-statutory-rates
title: Reviewing Statutory Deductions in Salary Assignments
knowledge_type: system_guide
scope_type: module
module_key: payroll
route_key: salary-claims
module_gate: payroll.statutory_rates
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
  - statutory
  - rates
  - settings
  - system-guide
active: true
---
# Reviewing Statutory Deductions in Salary Assignments

## Purpose

Review the EPF, PERKESO, and SIP deductions calculated for a salary assignment. VMECC does not currently expose a separate Statutory Rates page for editing the underlying rates.

## Before you begin

Confirm the employee, effective date, basic salary, and expected statutory treatment before checking the calculated deductions.

## Steps

1. Go to **Payroll Configuration** and open **Salary Assignments**.
2. Open an assignment, or select **Assign Salary** to prepare a new one.
3. Review **EPF**, **PERKESO**, and **SIP** under the calculated deductions together with the basic salary and allowances.
4. If the deductions are correct, complete the steps and select **Assign Salary**, then **Confirm set salary**. If they are not correct, stop and report the configuration issue before saving or issuing payroll records.

## What happens next

Saving the assignment stores its monetary inputs and calculated contribution amounts. Existing submitted claims keep the values already stored for them unless their own workflow explicitly recalculates them.

The payroll owner checks the calculation. Changing the underlying statutory-rate configuration is an operational deployment task until a dedicated, tested page is added.

## If something goes wrong

The assignment requires a valid employee and effective date. Monetary inputs must be zero or greater and remain within the limits shown by the form.

Salary assignments do not accept statutory source documents. Keep those documents in the approved operational document process.

Do not try to correct a rate by changing an employee's salary or contribution amount without authorization. Record the affected employee and period, leave the assignment unchanged, and ask System Administration to verify the deployed statutory configuration.

## Related tasks

Overtime Rates uses a separate visible configuration page; Company Information controls payroll identity fields.
