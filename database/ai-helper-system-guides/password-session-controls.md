---
key: password-session-controls
title: Resetting Passwords and Revoking Sessions
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
  - password
  - sessions
  - security
  - system-guide
active: true
---
# Resetting Passwords and Revoking Sessions

## Purpose

Send a password-reset link and review or revoke a user's signed-in sessions during account support or a security response.

## Before you begin

Verify the user's identity and support request. For session revocation, compare device, client mode, IP address, creation, last-seen, expiry, and active state.

## Steps

1. Go to **User Management**.
2. Choose **Reset password**, then select **Send reset link** to queue the link; verify the delivery result without asking for or setting the user's password.
3. Open the user's **Active Sessions**, review the target session, and choose **Revoke** for one session or **Revoke all** for an account-wide response.
4. Enter a support/security reason when applicable, confirm, reload, and verify revoked/logged-out timestamps and inactive state.

## What happens next

Active sessions have future expiry and no revoked/logged-out timestamp. Revocation records both timestamps and cannot restore the old session. Inactivation, lock, and deletion also revoke active sessions.

The user follows the secure reset link and signs in again. System Administration investigates unexpected devices or a reset email that was not delivered.

## If something goes wrong

Reset uses the stored user email. Session revocation targets a session belonging to the selected user; an optional reason is stored with revocation details. Tokens are cleared automatically and never returned.

No attachment is supported. Never request a password, reset token, session cookie, or one-time secret in notes.

Confirm the selected user and session. A missing session may already be expired or revoked. If the reset email is not delivered, check the recorded delivery result and repeat **Reset password**; never send credentials manually.

## Related tasks

User administration controls lock/status/deletion; audit logs record administrative security actions.
