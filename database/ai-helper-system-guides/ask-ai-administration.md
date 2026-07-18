---
key: ask-ai-administration
title: Ask AI Knowledge and Reports Administration
knowledge_type: system_guide
scope_type: module
module_key: profile
route_key: ai-helper-admin
module_gate: profile
required_permissions:
  - '*'
permission_match: any
allowed_roles:
  - System Administrator
version: 3
owner: System Administration
reviewed_on: 2026-07-17
review_due_on: 2026-10-17
release_status: final
tags:
  - ask-ai
  - knowledge
  - reports
  - administration
  - system-guide
active: true
---
# Ask AI Knowledge and Reports Administration

## Purpose

Review uploaded reference material and investigate responses that users have reported.

## Before you begin

Confirm that the material is safe to store, has a clear purpose, and is suitable for the users who will see it.

## Steps

1. Go to **Ask AI Knowledge**.
2. Open **Ask AI Knowledge** and filter by lifecycle status or module.
3. Open an entry and review its file name, visibility, current status, and review history.
4. Select **Approve**, **Reject**, **Enable**, **Disable**, **Retry**, or **Delete** as appropriate. Enter a note when rejecting an entry.
5. Open **Ask AI Reports**, review the cited response and user report, then update its status and administrator note.
6. Confirm that the new status is displayed and that the action appears in **Audit Logs**.

## What happens next

Uploaded material moves through processing and review before it can become active. A failed upload can be retried after the cause is corrected. Reported responses remain available until an administrator completes the review.

## If something goes wrong

Choose one of the statuses shown on the page. A rejection note is required and can contain up to 2,000 characters. An administrator note on a reported response can also contain up to 2,000 characters.

PDF uploads default to 10,240 KB and Markdown to 1,024 KB, subject to configured per-user and global quotas. Upload requires explicit acknowledgement.

Do not approve unknown or sensitive material. If processing fails again after **Retry**, check the uploaded file and correct the problem before trying once more. Built-in VMECC guides are released with the application and cannot be edited from this page.

## Related tasks

Use **Ask AI** for user questions and **Audit Logs** to review administrative actions.
