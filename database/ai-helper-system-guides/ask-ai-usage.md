---
key: ask-ai-usage
title: Using Ask AI Safely
knowledge_type: system_guide
scope_type: global
module_key: profile
route_key: global
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
  - ask-ai
  - help
  - system-guide
active: false
---

# Using Ask AI Safely

## Purpose

Explain how to ask VMECC usage questions, continue or remove a conversation, check sources, and report an unsuitable answer. Ask AI provides read-only guidance; it does not operate VMECC or replace emergency procedures and approved policy documents.

## Who can access it

Any signed-in VMECC user can open Ask AI. Retrieved guidance is filtered against that user's effective permissions, active modules, and the server-verified current route.

## Required permission/module state

The Profile module must be enabled and the Ask AI service must be configured. Page context improves relevance only; it never grants access to a guide.

## Where to find the page

Select **Ask AI** in the application header. The panel contains **Chat**, **History**, and **Knowledge** views.

## Prerequisites

Sign in with your own account. Before asking, remove passwords, tokens, bank account numbers, medical details, and personal record data that is not necessary for the question.

## Exact steps

1. Open **Ask AI**, select **Chat**, enter one current VMECC usage question of no more than 2,000 characters, and choose the response language when that control is shown.
2. Select **Send** once. Read the answer and its source list; a reference document is a clickable PDF source, while a **VMECC System Guide** source is non-clickable application guidance.
3. Ask a follow-up in the same conversation when it concerns the same task, or start a new conversation for a different task.
4. Open **History** to reopen or delete a conversation. Use **Report** on a specific answer to send feedback when it is incorrect, unsafe, or irrelevant.

## Fields and validation

A message is required and is limited to 2,000 characters. Supported response-language values are Automatic, English, and Bahasa Melayu. A conversation identifier must belong to the signed-in user. A report must target a visible Ask AI message.

## Statuses and transitions

A sent question progresses through generation to either a sourced answer or a clear unavailable/error response. Deleted conversations are removed from the user's history. Reporting an answer does not alter the answer or perform the requested VMECC action.

## Who performs the next action

The signed-in user verifies the guidance and performs any permitted action on the relevant VMECC page. An authorized administrator reviews reported answers and knowledge diagnostics.

## Attachments and limits

Chat accepts text only. The **Knowledge** view lists approved reference PDFs; ordinary users cannot upload system guides or download their Markdown source.

## Common errors and recovery

If sending is unavailable, confirm the session is active and retry once after the panel reports that generation has stopped. If the answer says current guidance was not found, open the relevant page or contact the responsible module owner. For emergency or operational-policy questions, use the cited approved reference document and established escalation channel.

## What Ask AI cannot do

Ask AI cannot open a record, click a control, upload a file, submit or approve a workflow, mark a claim paid, change a setting, reveal inaccessible guidance, verify that an action succeeded, or serve as emergency-policy authority.

## Related pages

Use the cited VMECC page for application steps and the **Knowledge** view for approved reference PDFs. Administrative knowledge review is separate and restricted to System Administrators.

## Source-of-truth code references for maintainers

Frontend: `vmecc-frontend/src/components/ai-helper/AiHelperPanel.js`, `vmecc-frontend/src/components/ai-helper/ChatView.js`, `vmecc-frontend/src/components/ai-helper/HistoryView.js`, and `vmecc-frontend/src/components/ai-helper/MessageBubble.js`. Backend: `vmecc-backend/routes/api.php`, `vmecc-backend/app/Http/Requests/AiHelper/StreamAiHelperMessageRequest.php`, and `vmecc-backend/app/Http/Controllers/AiHelperController.php`.

## Guide maintenance

Owner: System Administration. Candidate version: 3. Reviewed: 2026-07-17. Review due: 2026-10-17. Activation requires a matching hash-bound approval manifest record.
