---
key: inspection-workflow-settings
title: Inspection Workflow Settings
knowledge_type: system_guide
scope_type: module
module_key: reports.inspection
route_key: settings
module_gate: reports.inspection
required_permissions:
  - settings.manage
permission_match: any
allowed_roles: []
version: 3
owner: Operations
reviewed_on: 2026-07-17
review_due_on: 2026-10-17
release_status: final
tags:
  - inspection
  - workflow
  - settings
  - system-guide
active: true
---
# Inspection Workflow Settings

## Purpose

Configure the roles and safeguards used when an inspection moves from submission through review and approval.

## Before you begin

Identify the review role, fallback review role, approval role, team-scoping policy, missing-team policy, IC fallback policy, and self-action restrictions.

## Steps

1. Go to **Reporting Settings** and open **Inspection**.
2. Open **Inspection Workflow Settings** and load the current saved rules.
3. Select existing roles for Review, Fallback Review, and Approve.
4. Set Team-scoped AIC, Allow submit without team, Allow IC fallback review, Prevent self-review, and Prevent self-approve deliberately.
5. Save and reload the settings to verify the persisted values.
6. Test one representative submission and confirm its computed next role before production use.

## What happens next

Settings do not move reports. They govern Submitted -> Reviewed -> Approved and Rejected from Submitted or Reviewed.

After saving, the responsible system owner verifies the rules. Future inspections use the saved team assignments and role sequence.

## If something goes wrong

All three fallback role names are required strings up to 255 characters and must exist. Each workflow option uses a Yes or No selection.

Workflow settings contain no attachments.

An unknown role returns validation errors. If submissions become blocked, restore a known valid role path and verify team assignments; do not disable safeguards without owner review.

## Related tasks

Use **Inspection** for report work and **Reporting Settings** for the other report workflows.
