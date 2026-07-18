---
key: salary-assignments
title: Managing Salary Assignments
knowledge_type: system_guide
scope_type: module
module_key: payroll
route_key: salary-claims
module_gate: payroll.salary_assignments
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
  - salary
  - assignments
  - system-guide
active: true
---
# Managing Salary Assignments

## Purpose

Create and maintain effective-dated employee salary inputs used by salary claims and payroll calculations.

## Before you begin

Confirm the employee, effective date, basic salary, allowances, employee contributions, employer contributions, and whether an earlier assignment already covers the period.

## Steps

1. Go to **Payroll Configuration** and open **Salary Assignments**.
2. Choose **Assign Salary**, select the employee, enter the effective date and basic salary, then add supported allowances and contribution amounts.
3. Add an allowance name whenever its amount is greater than zero; review the calculated assignment summary and optional notes.
4. The form saves an in-progress draft automatically. When the review step is complete, select **Assign Salary**, then **Confirm set salary**, and verify the assignment in the list and history.
5. Edit or delete only the intended assignment, confirm the warning, and reload affected salary calculations before relying on them.

## What happens next

Drafts can be reopened later. Saving creates or updates the salary assignment and adds a history entry. Deleting removes the selected assignment but does not change salary details already saved in submitted claims.

Finance verifies effective periods before applicants create salary claims. Applicants cannot edit their salary assignments.

## If something goes wrong

Employee and effective date are required. Basic salary and monetary components must be from 0 to 99,999,999.99. Up to 50 allowances and 50 history notes are accepted; allowance names are limited to 120 characters and required for positive amounts; note text is limited to 2,000 characters.

Salary assignments do not accept file uploads. Do not place bank credentials, identity documents, or unrelated personal information in notes.

Correct missing employee, date, numeric range, or allowance name errors. Reload after concurrent changes. If salary claim values are unexpected, verify the effective assignment and approved overtime for that exact period.

## Related tasks

Statutory rates, overtime rates, company profile, claims, and payslips are independently accessed configuration or workflow areas.
