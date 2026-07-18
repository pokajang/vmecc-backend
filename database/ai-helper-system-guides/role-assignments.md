---
key: role-assignments
title: Managing Scoped Role Assignments
knowledge_type: system_guide
scope_type: module
module_key: users
route_key: users
module_gate: users
required_permissions:
  - roles.assign
permission_match: any
allowed_roles: []
version: 3
owner: System Administration
reviewed_on: 2026-07-17
review_due_on: 2026-10-17
release_status: final
tags:
  - users
  - roles
  - assignments
  - scope
  - system-guide
active: true
---
# Managing Role Assignments

## Purpose

Assign existing roles to a user with the correct coverage, team, effective dates, and primary-role setting.

## Before you begin

Confirm the intended role, whether it covers the whole system or one team, the start and end dates, and whether it should be the user's primary role.

## Steps

1. Go to **User Management** and open the user's **Roles**.
2. Open the target user and review all active, future, expired, primary, whole-system, and team assignments.
3. Add or edit the intended role, select its required **Scope** and **Team**, enter valid effective dates, and set primary only where appropriate.
4. Save, reload, and verify effective roles, access rights, team synchronization, dates, and audit history.

## What happens next

Assignments take effect on their start date and stop after their end date. Updating or deleting an assignment refreshes the user's active roles and team links.

Module owners verify that roles contain the correct access rights. Workflow owners must repair pending work when an assignment change leaves a stage without an actor.

## If something goes wrong

Choose a role and coverage option displayed on the form. A team-based role requires a valid team; a whole-system role must not keep a team. The end date cannot be before the start date, and the user must keep at least one role assignment.

No attachment is supported. Do not store approval evidence in role labels or assignment fields.

Choose the coverage and team required by the selected role, and correct invalid dates. Before removing a role that owns pending work, reassign that work through the approved administration process.

## Related tasks

Role access rights define capabilities; teams define team records; user administration controls account state.
