---
key: overtime-workflow-rules
title: Overtime Workflow-Rule Configuration
knowledge_type: system_guide
scope_type: module
module_key: overtime
route_key: overtime-management
module_gate: overtime.workflow_rules
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
  - overtime
  - workflow
  - system-guide
active: true
---
# Overtime Workflow-Rule Configuration

## Purpose

Configure reachable overtime review, optional recommendation, approval, and distinct actors.

## Before you begin

Confirm which roles will review, recommend, and approve new overtime requests, and make sure active users hold those roles.

## Steps

1. Go to **Overtime Management** and open **Overtime Rules**.
2. In **Overtime Approval Flow**, review applicant rules, fallback roles, recommendation, and distinct-role options.
3. Choose existing reachable stage roles; fallback Review and Approve are required, Recommend when enabled.
4. Save and resolve every unknown/unreachable-role validation error.
5. Submit a controlled new overtime request and confirm that it shows the intended review roles; existing pending requests stay unchanged.

## What happens next

The saved roles apply to new overtime requests. Existing pending requests keep the roles already shown in their history.

The review role acts first, followed by the recommendation role when enabled, and then the approval role.

## If something goes wrong

Choose existing roles from the page. Review and approval roles are required; the recommendation role is required only when recommendation is enabled.

No attachments.

Give an active user the missing role or choose another role that has an active user. Never enable a stage that nobody can complete.

## Related tasks

Use **Overtime Records** to confirm the sequence on a controlled new request. Existing pending requests are not rewritten by this setting.
