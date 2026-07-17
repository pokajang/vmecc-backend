---
key: profile-medical
title: Updating Personal Medical Information
knowledge_type: system_guide
scope_type: module
module_key: profile
route_key: profile
module_gate: profile
required_permissions:
  - self.profile.medical
permission_match: any
allowed_roles: []
version: 3
owner: Human Resources
reviewed_on: 2026-07-17
review_due_on: 2026-10-17
release_status: draft
tags:
  - profile
  - medical
  - system-guide
active: false
---

# Updating Personal Medical Information

## Purpose

Explain how a user records their own welfare-related medical information using the dedicated sensitive profile section.

## Who can access it

Signed-in users with the effective **self.profile.medical** permission.

## Required permission/module state

The Profile module must be enabled. Medical information remains separate from ordinary staff-directory fields and from other users' records.

## Where to find the page

Open **Profile** at /profile and locate **Medical Information**.

## Prerequisites

Prepare only current information relevant to workplace welfare. Use the **No known critical medical info** option only when the other medical fields should be cleared.

## Exact steps

1. Open /profile, locate **Medical Information**, and select **Edit**.
2. Either select **No known critical medical info**, or enter **Blood type**, **Allergies**, **Conditions**, **Medications**, and **Medical notes**.
3. Review the entries for accuracy, select **Save changes** once, and wait for the section to return to view mode.
4. Reopen **Edit** and correct the record promptly when the information changes.

## Fields and validation

Blood type is limited to 50 characters. Allergies, conditions, and medications are arrays whose individual entries are limited to 255 characters. Medical notes are limited to 1,000 characters. Selecting **No known critical medical info** submits cleared detail lists rather than contradictory medical entries.

## Statuses and transitions

The section changes between view and edit modes. The saved state is authoritative only after the profile update succeeds and the refreshed section shows it.

## Who performs the next action

The signed-in user keeps the information current. Contact Human Resources through an approved private channel for access or welfare follow-up.

## Attachments and limits

This section accepts no files. Do not add medical documents, credentials, or unrelated personal records in Medical notes.

## Common errors and recovery

Correct an entry that exceeds its limit and retry once. If the saved information is incomplete or wrong, edit it immediately. Use emergency procedures, not this profile page or Ask AI, during an urgent medical event.

## What Ask AI cannot do

Ask AI cannot diagnose, provide emergency medical advice, view or change stored medical details, decide fitness for duty, or reveal another person's medical information.

## Related pages

Emergency Contact is a separate profile section. Approved emergency and operational documents in Ask AI Knowledge remain the source for emergency procedures.

## Source-of-truth code references for maintainers

Frontend: `vmecc-frontend/src/views/profile/Profile.js`, `vmecc-frontend/src/views/profile/MedicalSection.js`, and `vmecc-frontend/src/views/profile/__tests__/MedicalSection.test.jsx`. Backend: `vmecc-backend/routes/api.php` and the medical validation in `vmecc-backend/app/Http/Controllers/AuthController.php`.

## Guide maintenance

Owner: Human Resources. Candidate version: 3. Reviewed: 2026-07-17. Review due: 2026-10-17. Activation requires a matching hash-bound approval manifest record.
