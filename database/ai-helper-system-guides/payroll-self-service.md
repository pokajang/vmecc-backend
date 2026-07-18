---
key: payroll-self-service
title: Viewing Payslips
knowledge_type: system_guide
scope_type: module
module_key: payroll
route_key: payroll
module_gate: payroll.payslips
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
  - payslip
  - self-service
  - system-guide
active: true
---
# Viewing Payslips

## Purpose

View issued payslips for the signed-in account and download an authorized payslip file.

## Before you begin

A payroll administrator must have issued a payslip for the employee and period. Use the account belonging to the payslip recipient.

## Steps

1. Go to **Payroll** and open **Payslips**.
2. Open **Payroll**, select **Payslips**, and review the available pay periods and payment dates.
3. Select the intended payslip and verify its period, gross pay, deductions, and net pay summary.
4. Choose **Download payslip**, wait for the file to finish downloading, and then open or save it.

## What happens next

Payslips do not have a self-service approval workflow. A payslip is visible when a saved record exists for the signed-in user.

Finance or an authorized payroll manager must correct or issue payroll records. The employee can only view and download their own issued payslips.

## If something goes wrong

The payslip list is read-only. Period, issued date, payment date, earnings, deductions, employer contributions, and net pay come from the saved record; the browser does not recalculate them for download.

The generated payslip is the downloadable file. There is no employee upload action on the Payslips tab.

An empty list means no payslip is available for the account or period. A forbidden download means the file does not belong to the signed-in user. Refresh after Finance confirms a corrected or newly issued payslip.

## Related tasks

Use **Claims** on **Payroll** for expense or salary claim records; claims have a separate access and workflow.
