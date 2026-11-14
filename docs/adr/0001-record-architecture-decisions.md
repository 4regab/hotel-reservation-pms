# 0001. Record architecture decisions

Status: Accepted
Date: 2026-11-15

## Context

Choices about how the system is built (how it is split, how the pieces talk, how the repository is laid out) are hard to reverse and easy to forget. Without a record, the same debates repeat, or a later change quietly contradicts the original reason.

## Decision

Keep one short file per decision in `docs/adr/`, numbered in order (`0002-...`). Each file has the same parts:

- Status: Proposed, Accepted or Superseded.
- Date.
- Context: the problem, in a few sentences.
- Options considered.
- Decision.
- Consequences, including the trade-offs.

Only record choices that shape the structure, affect a key quality, or are hard to reverse.
Do not edit an accepted record. If a decision changes, write a new record that supersedes it, and set the old one's status to Superseded with a link.

## Consequences

- The history of why the system looks as it does stays in the repository, next to the code.
- A small cost: a decision needs a few lines of writing before or just after it is made.

This follows Microsoft's guidance on [architecture decision records](https://learn.microsoft.com/en-us/azure/well-architected/architect-role/architecture-decision-record).
