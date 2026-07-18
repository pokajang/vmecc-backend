# AI Helper layman system-guide MVP contract

## Status

Implemented for the 51-guide version 3 corpus on 2026-07-17.

## Product contract

System guides are task instructions for ordinary VMECC users. Each guide starts from a visible page, uses the exact labels shown in the interface, gives ordered actions, explains the visible result, and provides practical recovery advice.

The application code is the verification source, not the language presented to users. Route, access, module, source-file, and content-hash details remain in frontmatter, database records, automated checks, or the matching technical dossier.

## Required user sections

Every seeded guide contains:

1. **Purpose**
2. **Before you begin**
3. **Steps** with at least three numbered actions
4. **What happens next**
5. **If something goes wrong**

Related tasks may be included when useful.

## Language rules

- Use visible page, tab, button, field, and status labels in bold.
- Use short sentences and one main action per step.
- Explain conditional actions only when the control is displayed.
- Describe review stages and next users in plain operational language.
- State field limits only when they help the user complete the form.
- Never expose routes, access codes, module keys, code paths, database details, request fields, or numeric system errors.

## Technical verification

Each guide has a matching file in `docs/ai-helper-system-guide-reviews/`. Dossiers contain frontend and backend evidence and are never seeded as Ask AI knowledge.

The catalog rejects a final guide when it has missing sections, fewer than three steps, insufficient interface labels, generic placeholder wording, raw paths, access codes, source-code paths, approval terminology, or maintainer-oriented implementation language.

## Release contract

- Exactly 51 Markdown guides must exist.
- Every guide must be version 3, `release_status: final`, and `active: true`.
- Every guide must have valid catalog metadata and a matching technical dossier.
- The seeder validates the complete corpus before mutation and writes it in one transaction.
- Rerunning the seeder must preserve entry identity and unchanged chunks.
- Obsolete system-guide entries are disabled without changing reference-document knowledge.
- Readiness compares every seeded content hash with its source guide.
- Retrieval remains restricted by trusted routes, current roles, access rights, and module availability.

## Evaluation contract

Every guide has authorized, unauthorized, forged-context, and disabled-module retrieval cases. The complete coverage suite therefore contains 204 cases.

Production is ready only after the source audit, atomic seed, readiness command, 204-case evaluation, backend tests, frontend tests, lint, and production build pass.
