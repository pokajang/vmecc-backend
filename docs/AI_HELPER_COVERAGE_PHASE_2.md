# Ask AI product coverage — Phase 2

Phase 2 hardens bilingual action recognition and routes operational questions
to the most specific authorized workflow and system guide.

## Implemented contract

- Canonical lifecycle actions cover view, create/write, edit/revise, submit,
  cancel/withdraw, review, approve/verify, reject, configure, download, and
  payment recording in English and Bahasa Melayu.
- ERCO, Drill, and Fitness Test authoring requests use dedicated task and
  workflow keys instead of the generic report-navigation fallback.
- Report review and approval use the report-management workflow.
- Claim payment recording and reversal are separated from claim submission.
- Inspection workflow configuration is separated from inspection conduct.
- Submitted report UI state removes authoring and submission steps from the
  deterministic response.
- Workflow selection remains permission-filtered through the workflow's
  registered system guide.
- The audit rejects unexpected task routing as well as missing tasks.

## Regression gate

Run:

```bash
php artisan ai-helper:coverage:audit
php artisan ai-helper:coverage:audit --json
```

Phase 2 is ready only when:

- `phase_1_ready` and `phase_2_ready` are both `true`;
- `phase_2_required` is `false`;
- every module and topic is represented;
- `queries.matched` equals `queries.total`;
- `queries.gaps` is zero; and
- `errors` and `gap_details` are empty.

The current committed corpus includes the original breadth sample plus
additional generic fallback, ambiguous wording, report lifecycle, payment
reversal, leave withdrawal, and mixed-language cases. Add new production
failure shapes to this corpus before fixing them so coverage cannot regress.
