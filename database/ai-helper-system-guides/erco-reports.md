---
key: erco-reports
title: ERCO Reports
knowledge_type: system_guide
scope_type: module
module_key: reports
route_key: erco
module_gate: reports.erco
required_permissions:
  - reports.erco.view
permission_match: any
allowed_roles: []
version: 3
owner: Operations
reviewed_on: 2026-07-17
review_due_on: 2026-10-17
release_status: final
tags:
  - reports
  - erco
  - system-guide
active: true
---
# ERCO Reports

## Purpose

Create, review, and retrieve Emergency Response Call-Out reports within the ERCO workflow.

## Before you begin

Confirm the incident, response facts, participating personnel, report owner, and current status and history before saving or acting.

## Steps

1. Go to **Reports** and open **ERCO**.
2. On **ERCO**, select **New ERCO Report** or open an existing ERCO record.
3. Complete the ERCO form and use **Save Draft** to retain incomplete work.
4. Review every required section, accept the submission declaration, and submit the report.
5. On an assigned record, use **Review**, **Approve**, or **Reject** only when the matching action is shown; rejection requires remarks.
6. Reload the detail and verify its status, latest details, and timeline.

## What happens next

The normal sequence is **Draft**, **Submitted**, **Reviewed**, then **Approved**. A submitted or reviewed report can instead be **Rejected**. If someone else changes the report first, reload it before acting.

The owner submits. The saved ERCO settings select the assigned review role, fallback review role, and approval role.

## If something goes wrong

Complete the required ERCO details before submitting. Rejection remarks are limited to 2,000 characters.

Add photos in **Post-Incident Analysis** under **Photos**. Use **Download** when a PDF copy of the report is required.

If another user changed the report, reload it and review the latest details before acting again. If a validation message appears, return to the named ERCO section. If access is denied, ask an administrator to check your assignment.

## Related tasks

Use **ERCO** for records and **Reporting Settings** to manage the ERCO review sequence.
