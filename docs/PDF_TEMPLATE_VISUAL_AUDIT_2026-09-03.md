# PDF Blade Template Visual and Implementation Audit

Audit date: 3 September 2026

## Outcome

The PDF system is suitable for release after the corrections in this audit. All seven document families render valid A4 PDFs, retain extractable text, stay below the 10 MB audit ceiling, and complete within the 20-second render budget. Eight inspection variants also pass their established visual baselines.

No business workflow, authorization, report data, or download contract was changed.

## Document inventory

| Document | Entry point | Renderer or view model | Blade template | Visual role |
| --- | --- | --- | --- | --- |
| Inspection report | `InspectionReportPdfController` | `InspectionReportPdfRenderer`, `InspectionReportViewDataBuilder` | `pdf.inspection_report` and inspection partials | Operational-report reference |
| Drill report | `DrillReportPdfController` | Controller payload assembly | `pdf.drill_report` | Operational report with dedicated evidence section |
| ERCO report | `ErcoReportPdfController` | Controller payload assembly | `pdf.erco_report` | Operational report |
| ER assessment | `ErAssessmentReportPdfController` | Controller payload assembly | `pdf.er_assessment_report` | Controlled assessment form |
| Fitness test report | `ReportController::exportFitness` | `FitnessTestReportViewBuilder` | `pdf.fitness_test_report` | Operational report and HTML preview |
| Fire-extinguisher exceptions | `FireExtinguisherExceptionExportController` | `FireExtinguisherExceptionExportBuilder`, `FireExtinguisherExceptionPdfRenderer` | `pdf.fire_extinguisher_exception_export` | Dense exception register |
| Payroll payslip | `PayrollPayslipController` | Payslip snapshot builder in controller | `pdf.payroll-payslip` | Specialized financial document |

## Resolved findings

### P1 — Fitness layout used unsupported flexbox

Dompdf did not reliably honor the flex rows. Metadata and group fields collapsed into vertical blocks, the typography was materially larger than the reporting family, and a representative two-part report consumed two pages.

Resolution:

- Replaced flex rows with Dompdf-compatible table display rules.
- Adopted the operational report header, typography, section and table hierarchy.
- Kept the HTML preview compatible and limited the fixed footer to PDF output.
- Removed the unconditional photograph page break.

The representative scenario now renders as one readable A4 page with aligned metadata and a page footer.

### P1 — ER assessment sign-off heading was orphaned

The `Sign-off` section heading could appear at the bottom of one page while the signature table started on the next page.

Resolution:

- Added heading keep-with-next behavior.
- Wrapped the heading and signature table in a non-splitting sign-off block.
- Added row, layout-image and repeated-table-header page rules.

### P2 — Renderer options drifted by endpoint

Inspection and exception exports explicitly disabled PHP and JavaScript, while other render paths relied on package defaults. Font defaults also varied between Helvetica and DejaVu Sans.

Resolution:

- Added `PdfDocumentOptions::secure()` as the single renderer contract.
- Applied it to all seven PDF render paths.
- Standardized the templates on DejaVu Sans for Unicode names and identifiers.
- Kept remote resources, embedded PHP and JavaScript disabled.

### P2 — Status badges could communicate the wrong state

Several reports used a fixed green or blue badge regardless of whether the report was submitted, reviewed, approved or rejected.

Resolution:

- Added shared status-badge markup and styles.
- Mapped approved/completed, submitted/resubmitted, reviewed/checked, rejected, draft and unknown states to explicit semantic tones.
- Added escaping and state-mapping coverage.

### P2 — Incomplete visual regression coverage

Only inspection reports had screenshot-based audit coverage, and that test still asserted a retired section heading.

Resolution:

- Updated the inspection assertion to the current `General photos and remarks` label.
- Added `PdfTemplateVisualAuditTest` for every non-inspection Blade PDF.
- Added reviewed page-count, page-size and perceptual-hash baselines.
- Added required-text, non-empty-page, file-size and render-duration assertions.

### P2 — Page identity was inconsistent

ERCO, ER assessment and Fitness did not consistently identify the current page.

Resolution:

- Added current-page labels to their fixed PDF footers.
- Preserved the existing generated timestamp and document identifiers.

## Reviewed design exceptions

- The ER assessment retains its controlled-form header, document code, revision and two-party signature layout.
- The fire-extinguisher exception export retains a denser register optimized for large inventories and repeated headers.
- The payslip retains its compact financial hierarchy and does not expose workflow metadata.
- Drill photographs retain a dedicated page break. Allowing them to flow fragmented a three-photo gallery across two pages without reducing total page count.
- Fitness remains usable as both an HTML preview and a PDF; PDF-only positioning is conditional.

## Remaining maintainability debt

These are P3 refactoring opportunities, not release blockers:

- `inspection_report.blade.php` still performs substantial data shaping before delegating to partials. More of that transformation can move into `InspectionReportViewDataBuilder`.
- `erco_report.blade.php` is monolithic and should eventually be divided into header, overview, team, chronology, analysis, evidence and sign-off partials.
- Drill and ERCO payload-to-view transformations can move from Blade into dedicated view-data builders.
- Footer drawing still uses two mechanisms: canvas scripting for inspection/exception exports and CSS counters for other reports. A shared footer renderer would simplify future total-page support.
- Status-tone CSS is shared, but header/card/metadata primitives remain duplicated across operational templates. Consolidation should follow visual-baseline coverage rather than precede it.

## Verification contract

Generate all non-inspection artifacts:

```powershell
$env:PDF_TEMPLATE_VISUAL_AUDIT='1'
php artisan test tests/Feature/PdfTemplateVisualAuditTest.php
```

Generate all inspection artifacts:

```powershell
$env:INSPECTION_PDF_VISUAL_AUDIT='1'
php artisan test tests/Feature/InspectionReportVisualAuditTest.php
```

Generated PDFs, PNG pages and manifests are written beneath `output/pdf/`. Baselines must only be changed after reviewing the generated pages; the update mode writes a candidate rather than silently replacing the accepted baseline.

## Release gate

A PDF change is acceptable only when:

1. Required content remains extractable.
2. No page is empty, clipped or overlapped.
3. Evidence and sign-off content remains associated with its source section.
4. Page size, page count and visual hash remain within the reviewed baseline unless the baseline change is intentional.
5. Authorization, media sanitation and download response tests remain green.
