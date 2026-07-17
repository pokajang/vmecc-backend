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
release_status: draft
tags:
  - leave
  - workflow
  - system-guide
active: false
---

# Leave Workflow-Rule Configuration

## Purpose

Configure reachable review, optional recommendation, approval, and distinct-actor rules for new leave submissions.

## Who can access it

Access requires the catalog permission and module gate in frontmatter; ordinary management access remains assignment-scoped.

## Required permission/module state

Both permission and module activation are checked server-side. Route context cannot grant access.

## Where to find the page

Open /staff/leave-management/rules.

## Prerequisites

Verify the exact person, period, record, current state, and effective assignment scope before changing data.

## Exact steps

1. Review applicant rules, fallback roles, recommendation, and distinct-approver options.
2. Choose existing applicant/review/approve roles and a recommend role when recommendation is enabled; keep non-empty safe fallbacks.
3. Save once; resolve every unknown or unreachable stage role reported by validation.
4. Submit a controlled new leave and confirm its stored snapshot; existing in-flight snapshots stay unchanged.

## Fields and validation

Role names exist/max 255. Applicant/review/approve required per rule; recommend optional. Safe fallback defaults use Human Resource.

## Statuses and transitions

Settings apply to new snapshots; existing Pending records keep their stored roles.

## Who performs the next action

The snapshot review role acts first, then recommendation when enabled, then approval.

## Attachments and limits

No attachments.

## Common errors and recovery

Add missing permission/active assignment or choose a reachable role. Never enable a stage without an actor.

## What Ask AI cannot do

Ask AI cannot open records, upload evidence, submit, review, approve, reject, cancel, change settings, bypass validation, or confirm success.

## Related pages

Self-service, management, configuration, and record APIs remain separate permission boundaries.

## Source-of-truth code references for maintainers

`vmecc-frontend/src/views/staff/leave-management/LeaveManagement.js`; `vmecc-backend/app/Http/Controllers/SettingsController.php`; `vmecc-backend/app/Services/LeaveWorkflowService.php`; `vmecc-backend/app/Services/WorkflowRbacAuditService.php`.

## Guide maintenance

Owner: Human Resources. Candidate version: 3. Reviewed: 2026-07-17. Review due: 2026-10-17. Activation requires a matching hash-bound approval manifest record.
