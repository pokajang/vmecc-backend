---
key: extinguisher-management
title: Fire Extinguisher Management
knowledge_type: system_guide
scope_type: module
module_key: reports.inspection
route_key: inspection
module_gate: reports.inspection
required_permissions:
  - reports.inspection.extinguishers.manage
permission_match: any
allowed_roles: []
version: 3
owner: Operations
reviewed_on: 2026-07-17
review_due_on: 2026-10-17
release_status: final
tags:
  - inspection
  - fire-extinguishers
  - assets
  - system-guide
active: true
---
# Fire Extinguisher Management

## Purpose

Maintain the fire-extinguisher catalog, location identity, lifecycle, coverage, and inspection history.

## Before you begin

Confirm the zone, main location, sub-location, **ID Loc. No.** or barcode/serial number, extinguisher type, certification date, and current details.

## Steps

1. Go to **Inspection** and open **Fire Extinguishers**.
2. Open the extinguisher catalog and search for the asset to prevent duplicates.
3. Add or edit the zone, location path, locator, type, and certification validity.
4. Confirm a duplicate only after comparing both catalog identities.
5. For lifecycle changes, choose Out of service, Return to service, Retire, or Restore and enter the required reason.
6. Reload and verify lifecycle, audit fields, latest saved details, coverage, and inspection history.

## What happens next

Active -> Out of service -> Active. Active or Out of service -> Retired; only Retired -> Active can restore. Lifecycle writes use checks that prevent overlapping updates.

An authorized asset manager verifies each catalog or lifecycle change; issue owners handle defects linked to the asset.

## If something goes wrong

Zone is at most 80 characters; location fields and locators are at most 190; type is at most 120; certification validity is a date. Creation requires the complete location and an ID Loc. No. or barcode/S/N. Batch creation accepts 1 to 25 items.

Catalog changes do not upload attachments. Inspection evidence and issue-resolution photos use report media in their own workflows.

If a possible duplicate is shown, compare it with the existing extinguisher before confirming. If another user changed the asset, reload it. Restore the asset before reopening an issue linked to a retired extinguisher.

## Related tasks

Use **Inspection** for reports and the issue work queue for defects created from extinguisher checks.
