---
key: audit-logs
title: Audit Logs
knowledge_type: system_guide
scope_type: module
module_key: audit
route_key: audit
module_gate: audit
required_permissions:
  - audit.view
permission_match: any
allowed_roles: []
version: 3
owner: System Administration
reviewed_on: 2026-07-17
review_due_on: 2026-10-17
release_status: final
tags:
  - audit
  - security
  - history
  - system-guide
active: true
---
# Audit Logs

## Purpose

Review the read-only history of important actions by person, affected record, and time period.

## Before you begin

Prepare the action, person, affected record, start time, end time, and expected change you want to investigate.

## Steps

1. Go to **Audit Logs**.
2. Open **Audit Logs** and set the narrowest relevant action, actor, subject, and date filters.
3. Compare event ID, timestamp, action, actor identity, subject, IP address, user agent, and details.
4. Cross-check related events and the current target record before drawing a conclusion.
5. Record the required event numbers under the approved incident or review process.

## What happens next

The page is read-only. Audit events do not transition and cannot be edited from this interface.

The authorized investigator or system owner follows the applicable security, access-review, or operational procedure.

## If something goes wrong

Use the action, person, affected-record, and date filters to narrow the list. The page normally shows 200 events and can show up to 500 at a time.

Audit logs contain no file attachments. Open an event to read the additional details recorded for that action.

Broaden a filter only after checking the event number and time zone. An empty result does not prove that an event did not occur outside the selected date range or under another action name.

## Related tasks

Use User Administration for subject-specific session and account state; use the relevant business record for current state.
