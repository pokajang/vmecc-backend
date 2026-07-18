---
key: leave-entitlements
title: Leave Entitlements
knowledge_type: system_guide
scope_type: module
module_key: leave
route_key: leave-management
module_gate: leave.assignments
required_permissions:
  - staff.leave.manage
permission_match: any
allowed_roles: []
version: 3
owner: Human Resources
reviewed_on: 2026-07-17
review_due_on: 2026-10-17
release_status: final
tags:
  - leave
  - workflow
  - system-guide
active: true
---
# Leave Entitlements

## Purpose

Maintain annual employee leave-type assignments used by balance validation.

## Before you begin

Confirm the employee, year, leave type, current entitlement, used days, and pending days before making a change.

## Steps

1. Go to **Leave Management** and open **Entitlements**.
2. Choose the exact employee, year, registered leave type, and entitlement.
3. Review used, pending, and remaining values; save once. An existing employee/year/type is updated.
4. Edit entitlement, used, or pending using non-negative values and save.
5. Delete only after confirming the effect on current leave requests.

## What happens next

The saved entitlement is used to calculate the employee's available leave balance.

The employee rechecks Leave balance; Human Resources resolves inconsistent consumption.

## If something goes wrong

Choose an employee and leave type shown on the page. Enter a year from 2000 to 2100, an entitlement from 0 to 365 days, and values of zero or more for used and pending days.

No attachments.

Choose a listed employee and leave type, and correct any value outside the allowed range. Recheck open leave requests before deleting an entitlement.

## Related tasks

Use **Leave Requests** to review applications and **Holidays** to maintain the dates excluded from leave-day calculations.
