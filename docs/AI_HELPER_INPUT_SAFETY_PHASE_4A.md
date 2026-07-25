# Ask AI Phase 4A: Input Safety and Query Quality

Phase 4A adds a hybrid input-assessment boundary before chat persistence,
retrieval, and answer generation.

## Decisions

- `allow`: continue through the normal Ask AI pipeline.
- `clarify`: request a missing action or outcome.
- `rephrase`: reject only objectively invalid or meaningless input.
- `refuse_sensitive`: stop actual credentials or sensitive values.
- `refuse_exfiltration`: stop requests for hidden, private, or unauthorized data.
- `semantic_review`: use the bounded semantic classifier for uncertain short text.

Short, misspelled, informal, or mixed-language input is not rejected merely
because it looks untidy. For example, `erco? how? xthu` is recoverable and
receives clarification.

## Privacy boundary

Sensitive and restricted input is assessed before message or thread creation.
The raw value is not stored in Ask AI messages, telemetry, or logs and is not
sent to the response provider. Telemetry stores only decision and reason codes,
confidence, recoverability, and whether semantic fallback was used.

## Release gate

```bash
php artisan ai-helper:input-safety:audit
php artisan ai-helper:input-safety:audit --json
```

The audit is deterministic and provider-free. Require exit status `0`,
`ready: true`, all required decisions covered, all cases passed, and empty
`errors` and `failures`.

The Phase 4A baseline established on 2026-07-24 is 22/22 cases and 6/6
decisions. The corpus includes English, Malay, mixed-language, recoverable
messy input, follow-ups, legitimate security questions, objective junk,
synthetic sensitive values, prompt injection, and data-exfiltration attempts.

The live semantic classifier is invoked only for low-confidence short input.
It has a five-second deadline, cannot override deterministic sensitive or
restricted-input decisions, and falls back to clarification if unavailable.
