# Ask AI Phase 3: Answer-Quality Hardening

Phase 3 turns the Phase 1 and Phase 2 coverage inventory into an executable
answer-quality contract. It verifies that broad product coverage also produces
the correct workflow, safe state-aware instructions, permission-filtered
guidance, and structured clarification when the request cannot be acted on
safely.

## Release gates

Run locally and on the live server after migrations and cache rebuilds:

```bash
php artisan ai-helper:coverage:audit
php artisan ai-helper:answer-quality:audit
php artisan ai-helper:answer-quality:audit --json
php artisan ai-helper:evaluate-knowledge --suite=answer-quality --json
```

The answer-quality audit is deterministic and does not call the configured AI
provider. A release is ready only when the command exits with status `0`,
`ready` is `true`, every registered workflow is covered, all cases pass, and
the `errors` and `failures` arrays are empty.

The Phase 3 baseline established on 2026-07-24 is:

- 26/26 answer-quality cases passed;
- 20/20 registered workflows covered;
- English and Malay representative requests covered;
- every workflow bound to a permission-scoped system guide; and
- structured clarification covered for missing report type, ambiguous action,
  missing record context, unsupported action, and compound requests.

## Runtime behavior

The query analyzer emits structured clarification metadata and suppresses an
execution task when the request is ambiguous or unsafe:

- `clarification_required`
- `clarification_reason`
- `clarification_option_keys`

Clarification options are filtered by the current user's live permissions. A
user is never offered a report type or workflow guide that their account
cannot access.

The workflow renderer uses a shared state policy for report records. Draft,
Submitted, Reviewed, Approved, and Rejected records receive only actions valid
for that state. If the requested action is unavailable, the answer states the
limitation and the valid next actions instead of inventing a transition.

## Telemetry

The Phase 3 migration adds the following fields to `ai_helper_runs`:

- `task_keys`
- `guide_keys`
- `clarification_required`
- `clarification_reason`
- `record_state_used`

Reliability metrics include `clarification_answers` and
`clarification_rate`. These fields contain routing and state labels only; they
do not add user message content to telemetry.

## Maintenance rule

When adding or changing a workflow, update
`config/ai_helper_answer_quality.php` in the same change. Use semantic required
and forbidden tokens rather than complete answer snapshots. Add a regression
case for any production miss before changing routing or rendering behavior.
