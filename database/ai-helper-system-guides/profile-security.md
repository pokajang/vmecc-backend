---
key: profile-security
title: Profile and Password Security
knowledge_type: system_guide
scope_type: module
module_key: profile
route_key: profile
module_gate: profile
required_permissions: []
permission_match: any
allowed_roles: []
version: 3
owner: System Administration
reviewed_on: 2026-07-17
review_due_on: 2026-10-17
release_status: final
tags:
  - profile
  - security
  - system-guide
active: true
---
# Profile and Password Security

## Purpose

Explain how a signed-in user updates their own account details, profile image, and password without exposing administrator account controls.

## Before you begin

Have the current password before changing it. Prepare a JPG, JPEG, PNG, or WebP image no larger than 2 MB before changing the profile image.

## Steps

1. Go to **Profile** and find **Personal** and **Security**.
2. At **Profile**, select **Edit**, update **Name**, **IC number**, **Mobile number**, **Home address**, or **State**, then select **Save changes** and confirm the refreshed values.
3. To change the image, choose an accepted image in the profile-image control, upload it, and wait for the new image to appear; use the remove control to delete the current image.
4. At **Profile**/security, enter **Current password**, **New password**, and **Confirm new password**, then submit once.
5. After a successful password change, sign in again on any session that was revoked and do not reuse the old password.

## What happens next

Profile changes appear after they are saved successfully. Changing the password signs out other active sessions to protect the account.

The signed-in user confirms the refreshed profile or signs in again. System Administration can assist with access recovery but cannot view the user's password.

## If something goes wrong

Name is required when supplied and is limited to 255 characters. IC number is limited to 100 characters, mobile number to 50, address to 500, and State must be a registered Malaysian state. A new password is at least 8 characters and must match its confirmation. An incorrect current password returns **Current password is incorrect**.

Only the profile-image control accepts a file: JPG, JPEG, PNG, or WebP image, maximum 2 MB.

Correct the named field and submit once more. If the current password is rejected, use the password-reset process rather than guessing repeatedly. If an image is rejected, confirm its file type and size.

## Related tasks

Banking Info, Emergency Contact, and Critical Medical Info are separate profile sections protected by their own effective access rights.
