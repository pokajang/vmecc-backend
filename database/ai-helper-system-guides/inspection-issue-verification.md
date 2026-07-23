---
key: inspection-issue-verification
title: Inspection Issue Verification
knowledge_type: system_guide
scope_type: module
module_key: reports.inspection
route_key: inspection
module_gate: reports.inspection
required_permissions:
  - reports.inspection.issues.verify
permission_match: any
allowed_roles: []
version: 3
owner: Operations
reviewed_on: 2026-07-17
review_due_on: 2026-10-17
release_status: final
tags:
  - inspection
  - issues
  - verification
  - system-guide
active: true
---
# Inspection Issue Verification

## Purpose

Independently verify corrective work and close a fire-extinguisher issue whose status is pending verification.

## Before you begin

Review asset identity, originating occurrences, corrective action, resolution notes, evidence, current status, and latest saved details; physically confirm remediation under the operating procedure.

## Steps

1. Go to **Inspection**, open **All Extinguishers**, and select the affected extinguisher.
2. In **Issues**, open an issue whose status is **pending verification** and compare it with the correct extinguisher and check criterion.
3. Review corrective action, resolution notes, photos, occurrence history, and event history.
4. Perform the required independent verification outside Ask AI.
5. Select **Verify and close**, enter the required **Verification notes**, and select **Confirm**.
6. Reload and confirm **closed** status, verifier, timestamp, event, and updated history.

## What happens next

Only pending verification -> closed is valid for **Verify and close**. A later authorized **Reopen** action can move closed -> open with a reason.

The authorized verifier closes the issue. If verification fails, the issue manager must use the supported corrective workflow rather than recording a false verification.

## If something goes wrong

A verification note is required and can contain up to 10,000 characters. If the issue changed while you were reviewing it, reload before selecting **Verify**.

Verify does not add attachments. Review up to 10 resolution evidence items linked during resolution.

If another user changed the issue, reload it. If it is no longer **pending verification**, follow the action shown for its current status. The issue manager must add any missing evidence before verification.

## Related tasks

Use issue management for assignment and resolution, and extinguisher management for asset lifecycle.
