# Domain docs

## Layout

This repo uses a single-context layout:

- `CONTEXT.md` at the root: domain terminology and glossary.
- `docs/adr/`: architecture decision records.

## Before exploring

Read `CONTEXT.md` and ADRs relevant to the work.

If they do not exist, proceed silently. The domain-modeling skill
creates them lazily when terms or decisions are resolved.

## Vocabulary

Use the glossary's terms when naming domain concepts in issues,
proposals, hypotheses, and tests.

If a concept is missing, reconsider the terminology or note the gap
for domain-modeling.

## Decision conflicts

Explicitly flag any proposal that contradicts an existing ADR,
identifying the ADR and explaining why it should be reconsidered.
