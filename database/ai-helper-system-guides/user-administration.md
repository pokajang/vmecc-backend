---
key: user-administration
title: Administering User Accounts
knowledge_type: system_guide
scope_type: module
module_key: users
route_key: users
module_gate: users
required_permissions:
  - users.manage
permission_match: any
allowed_roles: []
version: 3
owner: System Administration
reviewed_on: 2026-07-17
review_due_on: 2026-10-17
release_status: final
tags:
  - users
  - accounts
  - administration
  - system-guide
active: true
---
# Administering User Accounts

## Purpose

Create accounts and manage account status, lock state, deletion, restoration, and invitation delivery.

## Before you begin

Confirm the person's name, unique email, intended initial role assignment, account state, and whether the action could terminate active sessions.

## Steps

1. Go to **User Management**.
2. Choose **Create user**, enter name and unique email, provide at least one valid role or role assignment, and submit the invitation.
3. For an existing user, open row actions and select **Activate**, **Deactivate**, **Lock**, **Unlock**, **Delete**, or **Restore** only after verifying the target.
4. Confirm destructive actions, then reload and verify status, lock/deletion details, invitation delivery state, and audit history.

## What happens next

New accounts start **Active** with a random unusable password and an invitation/reset flow. Inactivation, locking, and deletion revoke active sessions. Deleted users may be restored; permanent deletion requires the explicit force path. An administrator cannot lock or delete their own account.

The invited user completes password setup. A role manager adds the required whole-system or team role assignments. System Administration investigates a failed invitation or an incorrect account state.

## If something goes wrong

Name and email are required and limited to 255 characters; email must be valid and unique. New accounts require a valid role or non-empty role-assignment list. Status accepts only **Active** or **Inactive**.

Account administration has no document upload. Profile images and staff documents use separate authorized workflows.

Use a unique valid email address and an existing role. Do not change a deleted account until it is restored. If the invitation is not delivered, check the delivery result shown for the account and retry through the invitation action.

## Related tasks

Use **Roles** for role assignments, **Sessions** for signed-in devices, **Staff** for employment details, and **Audit Logs** for account activity history.
