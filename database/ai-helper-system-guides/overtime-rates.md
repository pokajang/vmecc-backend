---
key: overtime-rates
title: Overtime Rules and Rates
knowledge_type: system_guide
scope_type: module
module_key: overtime
route_key: salary-claims
module_gate: overtime.rate_settings
required_permissions:
  - settings.manage
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
# Overtime Rules and Rates

## Purpose

Configure multipliers, eligible roles, and normal-hour calculation inputs for new overtime.

## Before you begin

Prepare the approved weekday, weekend, and public-holiday multipliers, eligible roles, and normal working hours before editing.

## Steps

1. Go to **Payroll Configuration** and open **Overtime Rates**.
2. Review weekday, weekend, and public-holiday multipliers and eligible roles.
3. Choose base-hour mode and normal-hours strategy; enter the divisor/global/default/role hours used by that strategy.
4. Save once and reload to verify normalized effective values.
5. Create a controlled new draft and verify classification/calculation without rewriting historical claims.

## What happens next

Settings are normalized and audited; new calculations use current values.

The responsible payroll owner checks the controlled calculation before the rates are used for live claims.

## If something goes wrong

Enter multipliers from 0 to 100, a divisor from 0 to 366, and daily hours from 0 to 24. Choose only calculation methods and roles displayed on the form.

No attachments.

Choose a listed role or calculation method and correct any number outside the allowed range. Reload the page before testing the calculation.

## Related tasks

Use **Overtime** for personal requests and **Overtime Records** under **Overtime Management** for review work. Rate changes apply to new calculations.
