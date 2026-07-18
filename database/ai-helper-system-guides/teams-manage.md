---
key: teams-manage
title: Creating and Managing Teams
knowledge_type: system_guide
scope_type: module
module_key: teams
route_key: teams
module_gate: teams.directory
required_permissions:
  - teams.manage
permission_match: any
allowed_roles: []
version: 3
owner: Operations
reviewed_on: 2026-07-17
review_due_on: 2026-10-17
release_status: final
tags:
  - teams
  - members
  - management
  - system-guide
active: true
---
# Creating and Managing Teams

## Purpose

Create teams and maintain their details, active members, roles, and images.

## Before you begin

Confirm the unique team name, group, status, intended members, member roles, primary member, effective start dates, and image source.

## Steps

1. Go to **Teams**.
2. Choose **Add Team**, select the default teams or enter a **Custom team name**, then select **Create**.
3. Open **Edit team**, update details, add eligible member options, set roles/primary indicators/start dates, and review conflicts.
4. Select a preset image or upload JPEG, PNG, WebP, or GIF up to 4 MB, then submit the complete update.
5. Reload and verify team detail, active membership, image, role-assignment synchronization, and audit history.

## What happens next

Removed members are marked as ended rather than remaining active. Updating membership also refreshes the affected role and team assignments. Deleting a team is a separate confirmed action.

Role administrators verify any role changes caused by membership updates. Roster managers schedule the updated team.

## If something goes wrong

Name is required and unique; group allows 100 characters; status 50. Member name is required up to 255, role up to 255, user must exist, and start date must be valid. A user cannot be an active member of another team. Image paths accept approved presets or stored team paths only.

Only the team image can be uploaded. Use JPEG, PNG, WebP, or GIF up to 4 MB; replacing an uploaded image removes the previous file.

Use a unique name, remove users already active in another team, and correct the member fields named in the message. If another manager changed the team, reload it before trying again.

## Related tasks

Team viewing, role assignments, staff records, and roster management remain separate controls.
