---
key: profile-emergency
title: Updating an Emergency Contact
knowledge_type: system_guide
scope_type: module
module_key: profile
route_key: profile
module_gate: profile
required_permissions:
  - self.profile.emergency
permission_match: any
allowed_roles: []
version: 3
owner: Human Resources
reviewed_on: 2026-07-17
review_due_on: 2026-10-17
release_status: draft
tags:
  - profile
  - emergency-contact
  - system-guide
active: false
---

# Updating an Emergency Contact

## Purpose

Explain how a user records and maintains their own emergency-contact details.

## Who can access it

Signed-in users with the effective **self.profile.emergency** permission.

## Required permission/module state

The Profile module must be enabled. This section updates only the authenticated user's emergency contact.

## Where to find the page

Open **Profile** at /profile and locate **Emergency Contact**.

## Prerequisites

Confirm the contact has agreed to be listed and verify their current name, relationship, mobile number, email, and address.

## Exact steps

1. Open /profile, locate **Emergency Contact**, and select **Edit**.
2. Enter **Emergency contact name**, choose **Relationship**, and enter the **Mobile number**, **Email**, and **Home address** that are needed.
3. Review the contact details, select **Save changes** once, and wait for the section to return to view mode.
4. Reopen **Edit** and update the contact promptly when any detail changes.

## Fields and validation

Name is limited to 255 characters, relationship to 100, phone to 50, and address to 500. Email must be a valid email address and is limited to 255 characters. The form formats the mobile number and provides the supported relationship choices.

## Statuses and transitions

The section changes between view and edit modes. The contact is saved only after the profile update API succeeds.

## Who performs the next action

The signed-in user verifies the displayed result. Human Resources handles access issues through the established private channel.

## Attachments and limits

This section accepts no files. Do not store identity documents, passwords, or unrelated sensitive information in the address field.

## Common errors and recovery

Correct the specific invalid email or over-limit field and retry once. If the contact is no longer appropriate, replace the saved details promptly. During an emergency, follow approved emergency procedures rather than relying on this page.

## What Ask AI cannot do

Ask AI cannot contact the person, validate their consent, view or update stored contact details, reveal another user's contact, or initiate emergency escalation.

## Related pages

Medical Information is a separate sensitive profile section. Approved emergency procedures remain in the reference-document Knowledge corpus.

## Source-of-truth code references for maintainers

Frontend: `vmecc-frontend/src/views/profile/Profile.js` and `vmecc-frontend/src/views/profile/EmergencySection.js`. Backend: `vmecc-backend/routes/api.php` and the emergency-contact validation in `vmecc-backend/app/Http/Controllers/AuthController.php`.

## Guide maintenance

Owner: Human Resources. Candidate version: 3. Reviewed: 2026-07-17. Review due: 2026-10-17. Activation requires a matching hash-bound approval manifest record.
