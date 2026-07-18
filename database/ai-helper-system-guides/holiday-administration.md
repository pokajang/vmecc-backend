---
key: holiday-administration
title: Holiday Administration
knowledge_type: system_guide
scope_type: module
module_key: leave
route_key: leave-management
module_gate: leave.holidays
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
# Holiday Administration

## Purpose

Maintain national and additional holidays used by leave-day calculation.

## Before you begin

Confirm the year, holiday name, date, and whether the holiday applies nationally or only to a selected location or state.

## Steps

1. Go to **Leave Management** and open **Holidays**.
2. Choose the year and review national and additional holidays separately.
3. For national rows retain the fixed key, edit name/date/applicable, and save the batch.
4. For an additional holiday, enter its name and date, choose where it applies, add the state when required, and save.
5. Edit or delete only after checking affected leave calculations.

## What happens next

Saving updates the national holidays for that year or adds the new local holiday. Each change is recorded in the activity history.

Human Resources verifies affected leave calculations.

## If something goes wrong

Enter a name of no more than 255 characters and a valid date. For national holidays, choose **Yes** or **No** under **Applicable**. For additional holidays, choose one of the locations shown on the form; a state name can contain up to 100 characters.

No attachments.

Correct any date, location, state, or text named in the message. Reload the selected year after saving several holidays.

## Related tasks

Use **Leave** to check how the saved holidays affect a personal leave request. Use **Entitlements** for annual leave balances.
