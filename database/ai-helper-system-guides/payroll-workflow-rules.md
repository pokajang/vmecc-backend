---
key: payroll-workflow-rules
title: Configuring Payroll Claim Workflow Roles
knowledge_type: system_guide
scope_type: module
module_key: payroll
route_key: salary-claims
module_gate: payroll.workflow_rules
required_permissions:
  - settings.manage
permission_match: any
allowed_roles: []
version: 3
owner: Finance
reviewed_on: 2026-07-17
review_due_on: 2026-10-17
release_status: final
tags:
  - payroll
  - workflow
  - settings
  - system-guide
active: true
---
# Configuring Payroll Claim Workflow Roles

## Purpose

Set the fallback roles that own the check, review, and approval stages for newly submitted payroll claims.

## Before you begin

Ensure the intended roles already exist and have active users assigned for the teams or areas where claims will be submitted.

## Steps

1. Go to **Payroll Configuration** and open **Workflow Rules**.
2. In **Salary Workflow Rules**, review the **Check**, **Review**, and **Approve** role selections under **Global Workflow Rule**.
3. Select one eligible role for each stage and do not leave a stage blank.
4. Select **Save**, reload the page, and confirm the selected roles remain displayed.

## What happens next

New claims use the saved check, review, and approval roles. Existing pending claims keep the roles and stage already shown in their history; changing the setting does not skip or rewrite them.

When a claim is submitted, the **Check** role acts first, followed by the **Review** role and **Approve** role. The next person must have an active assignment for that claim.

## If something goes wrong

**Check**, **Review**, and **Approve** are required. Each selected role must exist and be eligible to manage salary claims.

Workflow settings do not accept attachments. Evidence remains attached to individual claims.

Choose an existing role with the required access. If a stage becomes ownerless, repair role assignments before accepting new submissions. Do not change a role name to work around a pending claim; use the audited reassignment process.

## Related tasks

Role access rights and role assignments determine eligibility; claim management enforces each stored stage.
