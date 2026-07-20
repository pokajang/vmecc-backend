---
key: inspection-manage
title: Creating and Managing Inspections
knowledge_type: system_guide
scope_type: module
module_key: reports.inspection
route_key: inspection
module_gate: reports.inspection
required_permissions:
  - reports.manage
  - reports.inspection.conduct
permission_match: any
allowed_roles: []
version: 3
owner: Operations
reviewed_on: 2026-07-17
review_due_on: 2026-10-17
release_status: final
tags:
  - inspection
  - conduct
  - workflow
  - system-guide
active: true
---
# Creating and Managing Inspections

## Purpose

Conduct an inspection, save work safely, submit the completed report, and perform authorized workflow actions.

## Before you begin

Confirm duty context, inspection type, location or asset, inspector identity, checklist data, evidence, and network state before final submission.

## Steps

1. Go to **Inspection**.
2. Select **Conduct Inspection** and choose the implemented inspection type.
3. Confirm the required duty context and complete each location, asset, checklist, finding, and evidence section.
4. Save the draft, then reopen it and verify the recovered content before continuing.
5. Review all findings, then select **Continue to Review** once every required field is complete.
6. Confirm the report details on the review page, then select **Submit Report**.
7. For assigned workflow work, open the record and select **Review**, **Approve**, or **Reject** only when that action is shown; reload and verify the new status.

## What happens next

The normal sequence is **Draft**, **Submitted**, **Reviewed**, then **Approved**. A submitted or reviewed inspection can instead be **Rejected**. If someone else changes the inspection first, reload it before acting.

The inspector submits. The **AIC** for the assigned team or the fallback reviewer acts next, followed by the configured approver. The saved self-review and self-approval rules still apply.

## If something goes wrong

Complete every required inspection detail and check before submitting. Workflow remarks can contain up to 2,000 characters. Reload the inspection before retrying an action after a conflict.

Add evidence through the report's **Attachments** area and wait for each file to finish uploading before submitting.

Restore a saved draft after an interruption. Correct the exact field named in the message. If duty or workflow information is missing, complete it before submitting again.

## Related tasks

Use **Inspection** for records, **Fire Extinguishers** for assets, and **Inspection Workflow Settings** for workflow configuration.
