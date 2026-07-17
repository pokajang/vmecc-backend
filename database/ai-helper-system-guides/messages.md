---
key: messages
title: Reading and Sending Messages
knowledge_type: system_guide
scope_type: module
module_key: messages
route_key: messages
module_gate: messages
required_permissions:
  - self.messages
permission_match: any
allowed_roles: []
version: 3
owner: System Administration
reviewed_on: 2026-07-17
review_due_on: 2026-10-17
release_status: draft
tags:
  - messages
  - communication
  - system-guide
active: false
---

# Reading and Sending Messages

## Purpose

Explain how to find a contact, read a conversation, send text or one image, mark messages read, and remove message history.

## Who can access it

Signed-in users with the effective **self.messages** permission.

## Required permission/module state

The Messages module must be enabled. A user can read an attachment only when they are its sender or recipient.

## Where to find the page

Open **Messages** at /messages.

## Prerequisites

Choose the intended recipient before composing. Prepare a JPEG, PNG, GIF, or WebP image no larger than 2 MB when an image is necessary.

## Exact steps

1. Open /messages, use **New chat** or select an existing conversation, and confirm the recipient name before composing.
2. Enter a message of no more than 2,000 characters, attach at most one accepted image when needed, and select **Send message** once.
3. Keep the conversation open to load messages and mark received items read; use **Unread** in the conversation list to find threads with unread items.
4. Use **Delete message** to remove an individual item from your view. Use **Delete conversation** and choose **Delete for me**; **Delete for everyone** is shown only when the current user is the conversation starter.

## Fields and validation

The recipient must be an existing non-deleted user and cannot be inferred from page context. Subject is optional and limited to 255 characters. Body is optional only when an attachment is present and is limited to 2,000 characters. A message requires a body, an attachment, or both.

## Statuses and transitions

Messages are sent, received, and read; read time controls the **Seen** indicator on the latest sent message. Deleting for the current user hides that history from their view. Deleting for everyone permanently removes the conversation for both parties when the server authorizes it.

## Who performs the next action

The recipient reads and replies. The sender should not resend while the first request is still processing.

## Attachments and limits

One image can be attached to a message. Accepted types are JPG/JPEG, PNG, GIF, and WebP; server maximum is 2 MB. The client resizes images above 1,920 pixels on the longest side before upload. Only the sender or recipient can retrieve the stored image.

## Common errors and recovery

If the send control is disabled, add text or an image and select a recipient. If upload fails, confirm type and size, remove the image, and choose it again. If threads fail to load, reload once; drafts remain local to the signed-in user's browser.

## What Ask AI cannot do

Ask AI cannot read, send, delete, or mark messages; identify hidden contacts; retrieve message attachments; or disclose another user's conversation.

## Related pages

Staff and team pages may offer a message entry point, but /messages rechecks the Messages module and **self.messages** permission.

## Source-of-truth code references for maintainers

Frontend: `vmecc-frontend/src/views/messages/Messages.js`, `vmecc-frontend/src/components/messages/ChatList.js`, `vmecc-frontend/src/components/messages/ChatThread.js`, and `vmecc-frontend/src/views/messages/messageAttachmentUtils.js`. Backend: `vmecc-backend/routes/api.php`, `vmecc-backend/app/Http/Controllers/MessageController.php`, and `vmecc-backend/app/Http/Controllers/MessageAttachmentController.php`.

## Guide maintenance

Owner: System Administration. Candidate version: 3. Reviewed: 2026-07-17. Review due: 2026-10-17. Activation requires a matching hash-bound approval manifest record.
