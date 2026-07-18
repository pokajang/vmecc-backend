---
key: leave-workflow-rules
title: Leave Workflow-Rule Configuration
knowledge_type: system_guide
scope_type: module
module_key: leave
route_key: leave-management
module_gate: leave.workflow_rules
required_permissions:
  - settings.manage
permission_match: any
allowed_roles: []
version: 3
owner: Human Resources
reviewed_on: 2026-07-17
review_due_on: 2026-10-17
release_status: final
tags:
  - leave
  - workflow
  - system-guide
active: true
---
# Leave Workflow-Rule Configuration

## Purpose

Configure reachable review, optional recommendation, approval, and distinct-actor rules for new leave submissions.

## Before you begin

Confirm which roles will review, recommend, and approve new leave requests, and make sure active users hold those roles.

## Steps

1. Go to **Leave Management** and open **Workflow Rules**.
2. Review applicant rules, fallback roles, recommendation, and distinct-approver options.
3. Choose existing applicant/review/approve roles and a recommend role when recommendation is enabled; keep non-empty safe fallbacks.
4. Save once; resolve every unknown or unreachable stage role reported by validation.
5. Submit a controlled new leave request and confirm that it shows the intended review roles; existing pending requests stay unchanged.

## What happens next

The saved roles apply to new leave requests. Existing pending requests keep the roles already shown in their history.

The review role acts first, followed by the recommendation role when enabled, and then the approval role.

## If something goes wrong

Choose existing roles from the page. Applicant, review, and approval roles are required; the recommendation role is required only when recommendation is enabled.

No attachments.

Give an active user the missing role or choose another role that has an active user. Never enable a stage that nobody can complete.

## Related tasks

Use **Leave Requests** to confirm the sequence on a controlled new request. Existing pending requests are not rewritten by this setting.
