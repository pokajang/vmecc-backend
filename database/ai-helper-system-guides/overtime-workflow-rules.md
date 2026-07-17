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
release_status: draft
tags:
  - overtime
  - workflow
  - system-guide
active: false
---

# Overtime Workflow-Rule Configuration

## Purpose

Configure reachable overtime review, optional recommendation, approval, and distinct actors.

## Who can access it

Access requires the catalog permission and module gate in frontmatter; ordinary management access remains assignment-scoped.

## Required permission/module state

Both permission and module activation are checked server-side. Route context cannot grant access.

## Where to find the page

Open /staff/overtime-management/rules.

## Prerequisites

Verify the exact person, period, record, current state, and effective assignment scope before changing data.

## Exact steps

1. Review applicant rules, fallback roles, recommendation, and distinct-actor options.
2. Choose existing reachable stage roles; fallback Review and Approve are required, Recommend when enabled.
3. Save and resolve every unknown/unreachable-role validation error.
4. Submit a controlled new record and verify its snapshot; in-flight snapshots stay unchanged.

## Fields and validation

Role names exist/max 255. Defaults: Contract Manager review, Human Resource recommend, Client Contract Manager approve.

## Statuses and transitions

New submissions snapshot settings; Pending records retain stored stages/roles.

## Who performs the next action

Snapshot review role acts first, then recommend when enabled, then approve.

## Attachments and limits

No attachments.

## Common errors and recovery

Add route permission/active scope or choose a reachable role; never strand in-flight records.

## What Ask AI cannot do

Ask AI cannot open records, upload evidence, submit, review, approve, reject, cancel, change settings, bypass validation, or confirm success.

## Related pages

Self-service, management, configuration, and record APIs remain separate permission boundaries.

## Source-of-truth code references for maintainers

`vmecc-frontend/src/views/staff/overtime-management/OvertimeManagement.js`; `vmecc-backend/app/Http/Controllers/SettingsController.php`; `vmecc-backend/app/Services/OvertimeWorkflowService.php`; `vmecc-backend/app/Services/WorkflowRbacAuditService.php`.

## Guide maintenance

Owner: Human Resources. Candidate version: 3. Reviewed: 2026-07-17. Review due: 2026-10-17. Activation requires a matching hash-bound approval manifest record.
