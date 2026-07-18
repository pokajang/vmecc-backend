---
key: roster-manage
title: Creating and Publishing Rosters
knowledge_type: system_guide
scope_type: module
module_key: roster
route_key: roster
module_gate: roster
required_permissions:
  - rosters.manage
permission_match: any
allowed_roles: []
version: 3
owner: Operations
reviewed_on: 2026-07-17
review_due_on: 2026-10-17
release_status: final
tags:
  - roster
  - shifts
  - publish
  - management
  - system-guide
active: true
---
# Creating and Publishing Rosters

## Purpose

Assign teams to configured shifts, save draft schedules, validate conflicts, and publish authoritative roster entries.

## Before you begin

Confirm the schedule name, shift definitions, active teams, date range, leave or availability conflicts, and existing draft or published assignments.

## Steps

1. Go to **Roster**.
2. Open the intended date range, choose **Change**, and assign an eligible team or clear the shift for each edited date.
3. Resolve every same-day conflict; one team cannot be assigned to more than one shift on the same date.
4. Choose **Save draft** to store 1 to 500 entries, then reload and review all draft labels and team/shift combinations.
5. Choose **Publish**, enter the required schedule label of up to 100 characters, confirm, and verify the published state, publisher, time, and affected-team notifications.

## What happens next

Saving creates **Draft** entries. Publishing changes them to **Published** and records who published them and when. Clearing a shift removes that date and shift entry. Verify the complete schedule before operational use.

Affected team members receive publication notifications. Team managers correct membership; leave managers resolve leave records rather than editing roster history to hide conflicts.

## If something goes wrong

Each entry requires a valid date and at least one shift. Choose a shift and team displayed on the page. You can save from 1 to 500 entries at once, and publishing requires a schedule label.

Rosters have no attachment upload. **Print** or **Export** produces a copy of the current schedule; it does not publish or approve it.

Resolve same-team same-date conflicts, invalid shifts, missing teams, and batches over 500 entries. If another manager changes the roster, reload it and review the complete schedule before publishing.

## Related tasks

Team management, shift settings, roster viewing, and leave roster-impact checks are separately authorized.
