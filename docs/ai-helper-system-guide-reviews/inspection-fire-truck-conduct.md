# Review dossier: inspection-fire-truck-conduct

Status: version 3 final workflow verification.

- Contract: `/inspection/new`; `reports.inspection.conduct` or `reports.manage`; module `reports.inspection`.
- Verified behavior: fire-truck selection, compartment selection, prepared daily and one-off rows, required readings, issue evidence, draft recovery, review, and submission.
- Security: the guide does not bypass truck availability, required checklist values, report authorization, or workflow state.

## Verified user workflow

- Route registration: `vmecc-frontend/src/routes.js`.
- Inspection type and workflow definition: `vmecc-frontend/src/views/inspection/types/frt-daily/definition.js`.
- Prepared checklist and required readings: `vmecc-frontend/src/views/inspection/types/frt-daily/reference.js`.
- Visible setup sequence: `vmecc-frontend/src/views/inspection/form/components/InspectionFormSetupSections.js`.
- API registration and middleware boundary: `vmecc-backend/routes/api.php`.
- Submission validation: `vmecc-backend/app/Services/InspectionPayloadService.php`.

## Verification coverage

- Focused automated evidence: `vmecc-frontend/src/views/inspection/__tests__/InspectionForm.workflow.test.jsx`.
- Inspection-type registry evidence: `vmecc-frontend/src/views/inspection/__tests__/inspectionTypeRegistry.test.js`.

## Discrepancies

The frontend definition retains the legacy alias **FRT Daily Inspection**, while the visible current title is **Fire Truck Daily Readiness**. The guide uses the current visible title.
