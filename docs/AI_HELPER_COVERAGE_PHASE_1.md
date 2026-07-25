# Ask AI product coverage — Phase 1

Phase 1 establishes an executable inventory and failure map for Ask AI. It does
not claim that every user intent is implemented.

## Coverage contract

- Every key in `ModuleCatalog` is classified exactly once.
- Every registered system guide must point to a classified module.
- Every deterministic workflow must pass its existing registry validation.
- Every registered topic alias must appear in at least one representative query.
- A bilingual representative-query corpus measures topic, operation, and task
  recognition across reports, inspections, self-service, workforce,
  administration, settings, communication, dashboards, and audit.
- Query mismatches are reported as Phase 2 gaps. They do not hide or invalidate
  a complete Phase 1 inventory.

The classifications are:

- `deterministic_workflow`: an existing structured navigation workflow.
- `grounded_guidance`: answers grounded in a maintained system guide.
- `product_navigation`: route or feature-location guidance.
- `clarification_required`: intentionally requires disambiguation.
- `intentionally_unsupported`: infrastructure with no standalone user flow.

## Run the audit

```bash
php artisan ai-helper:coverage:audit
php artisan ai-helper:coverage:audit --json
```

The command exits unsuccessfully for structural coverage defects, such as a new
unclassified module or an invalid guide/workflow reference. It can exit
successfully with `phase_2_required: true` when the inventory is sound but the
analyzer does not yet recognize every representative intent.

## Phase 1 exit criteria

Phase 1 is ready when:

- `phase_1_ready` is `true`;
- all catalog modules are classified with no missing, unknown, or duplicate
  assignments;
- guide and workflow registry validation has no errors; and
- every observed query mismatch is visible in `gap_details`.

Phase 2 should use the query corpus as its regression target, reducing the gap
count without weakening the structural checks.
