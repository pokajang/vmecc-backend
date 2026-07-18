---
key: fitness-reports
title: Fitness-Test Reports
knowledge_type: system_guide
scope_type: module
module_key: reports
route_key: fitness
module_gate: reports.fitness_test
required_permissions:
  - reports.fitness.view
permission_match: any
allowed_roles: []
version: 3
owner: Operations
reviewed_on: 2026-07-17
review_due_on: 2026-10-17
release_status: final
tags:
  - reports
  - fitness-test
  - system-guide
active: true
---
# Fitness-Test Reports

## Purpose

Record and process fitness-test results under the Fitness Test report workflow.

## Before you begin

Confirm the subject, test date, assessment data, recorded measurements, assessor, and required declarations before submission.

## Steps

1. Go to **Reports** and open **Fitness Test**.
2. On **Fitness Test**, select **New Fitness Test Report**.
3. Enter the fitness-test data and save a draft until all required measurements are ready.
4. Review the report, accept the submission declaration, and submit it.
5. The assigned reviewer and approver use only the action exposed for the current status.
6. Reload and verify the stored measurements, status, latest details, and workflow timeline.

## What happens next

The normal sequence is **Draft**, **Submitted**, **Reviewed**, then **Approved**. A submitted or reviewed report can instead be **Rejected**, with remarks explaining why. If someone else changes the report first, reload it before acting.

The owner submits; the configured Fitness Test reviewer acts next, followed by the configured approver.

## If something goes wrong

Complete the required Fitness Test measurements and declarations before submitting. Rejection remarks are limited to 2,000 characters.

Add any available photos in the form section that displays **Photos**. A dedicated download action is not currently shown for Fitness Test reports.

Correct the field named in the validation message. If another user changed the report, reload it before trying again. If access is denied, ask an administrator to check your Fitness Test assignment.

## Related tasks

Use **Fitness Test** for records and **Reporting Settings** to manage the Fitness Test review sequence.
