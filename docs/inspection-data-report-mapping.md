# Inspection Data To Report Mapping

This checklist tracks the intended mapping for inspection form data across the submitted payload,
backend normalization, frontend read-only report surfaces, and the final PDF template.

| Inspection type | Primary row payload | Optional item info | Issue evidence | Report surfaces |
| --- | --- | --- | --- | --- |
| General / HSE | `inspectionIssues`, HSE detail fields | General/HSE remarks fields | Finding descriptions, action fields, photos | Frontend detail/review and PDF findings/photos |
| Fire Extinguisher | `fireExtinguisherChecks` | `remarks`, `photos` | Per-status `*Remarks`, `*Photos` | Frontend detail/review and PDF table/evidence/photos |
| Hydraulic Rescue Tools | `hydraulicChecks` | `remarks`, `photos` | Per-status `*Remarks`, `*Photos` | Frontend detail/review and PDF table/evidence/photos |
| ER/AUX | `erAuxChecks` | `additionalNotes`, `photos` | `defectRemarks`, `defectPhotos` | Frontend detail/review and PDF table/evidence/photos |
| SCBA standard | `scbaBackPlateChecks`, `scbaCylinderChecks`, `scbaFaceMaskChecks` | `remarks`, `photos` | Per-status `*Remarks`, `*Photos` | Frontend detail/review and PDF table/evidence/photos |
| SCBA custom | `scbaCustomSections[].rows` | `remarks`, `photos` | Custom-field `*Remarks`, `*Photos` | Frontend detail/review and PDF table/evidence/photos |
| High Angle | `highAngleChecks` | `additionalNotes`, `additionalPhotos` | `conditionRemarks`, `conditionPhotos` | Frontend detail/review and PDF table/evidence/photos |
| FRT Daily | `frtDailyChecks` | `additionalNotes`, `additionalPhotos` | Issue-row `remarks`, `photos` | Frontend detail/review and PDF roster/evidence/photos |
| FRT One-Off | `frtOneOffChecks` | `additionalNotes`, `additionalPhotos` | Not Good `remarks`, `photos` | Frontend detail/review and PDF checklist/evidence/photos |

Rules:
- Optional item info must not satisfy required issue evidence validation.
- Nested item photos count toward inspection photo guardrails.
- CamelCase is canonical in persisted payloads; snake_case inputs are accepted where supported.
- Generic `photos` output remains available and may duplicate item-level inline photos in report displays.
