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
release_status: draft
tags:
  - profile
  - security
  - system-guide
active: false
---

# Profile and Password Security

## Purpose

Explain how a signed-in user updates their own account details, profile image, and password without exposing administrator account controls.

## Who can access it

Any signed-in VMECC user can manage their own account and password.

## Required permission/module state

The Profile module must be enabled. These controls update only the authenticated user's record.

## Where to find the page

Open **Profile** at /profile. Open **Security** at /profile/security.

## Prerequisites

Have the current password before changing it. Prepare a JPG, JPEG, PNG, or WebP image no larger than 2 MB before changing the profile image.

## Exact steps

1. At /profile, select **Edit**, update **Name**, **IC number**, **Mobile number**, **Home address**, or **State**, then select **Save changes** and confirm the refreshed values.
2. To change the image, choose an accepted image in the profile-image control, upload it, and wait for the new image to appear; use the remove control to delete the current image.
3. At /profile/security, enter **Current password**, **New password**, and **Confirm new password**, then submit once.
4. After a successful password change, sign in again on any session that was revoked and do not reuse the old password.

## Fields and validation

Name is required when supplied and is limited to 255 characters. IC number is limited to 100 characters, mobile number to 50, address to 500, and State must be a registered Malaysian state. A new password is at least 8 characters and must match its confirmation. An incorrect current password returns **Current password is incorrect**.

## Statuses and transitions

Profile changes become saved after the update API succeeds. A password change updates the credential and revokes other authenticated sessions for the password-change reason.

## Who performs the next action

The signed-in user confirms the refreshed profile or signs in again. System Administration can assist with access recovery but cannot view the user's password.

## Attachments and limits

Only the profile-image control accepts a file: JPG, JPEG, PNG, or WebP image, maximum 2 MB.

## Common errors and recovery

Correct the named invalid field and submit once more. If the current password is rejected, use the password-reset process rather than guessing repeatedly. If an image is rejected, confirm its actual MIME type and size.

## What Ask AI cannot do

Ask AI cannot read or change a password, upload or remove a profile image, modify another user's profile, reveal session credentials, or confirm that the save succeeded.

## Related pages

Banking, Emergency Contact, and Medical Information are separate profile sections protected by their own effective permissions.

## Source-of-truth code references for maintainers

Frontend: `vmecc-frontend/src/views/profile/Profile.js`, `vmecc-frontend/src/views/profile/AccountSection.js`, and `vmecc-frontend/src/views/profile/SecuritySection.js`. Backend: `vmecc-backend/routes/api.php` and `vmecc-backend/app/Http/Controllers/AuthController.php`.

## Guide maintenance

Owner: System Administration. Candidate version: 3. Reviewed: 2026-07-17. Review due: 2026-10-17. Activation requires a matching hash-bound approval manifest record.
