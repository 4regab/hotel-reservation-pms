# 0003. Service boundaries are enforced in CI

Status: Accepted
Date: 2026-11-15

## Context

The system is four services that each own a database and talk over HTTP and events. All their code lives in one repository, so nothing stops one service from reading another's classes directly. That would couple them in a way the design does not allow, and it would break the day they are deployed apart.

When this was checked, one such break existed: the portal read the list of test cards from `Billing\MockCheckout`.

## Options considered

1. **Rely on review.** No tooling, but it fails quietly, and it had already failed once.
2. **A check that fails the build.** A small script reads each file's PHP names and rejects cross-service use.

## Decision

Option 2.

- A service may use only `Lib\` (the shared code in `src/shared`) and its own namespace. `src/shared` may use no service.
- `php scripts/check-boundaries.php` enforces this by reading real PHP names (use lines, new, static calls). Words in strings and comments are ignored.
- CI runs it on every push. It is also listed in `docs/SETUP.md`.
- The existing break was fixed by moving the card list to `Lib\TestCards`. The old constant on `MockCheckout` was removed, with no alias.

## Consequences

- Anything two services both need goes in `src/shared`. That folder will grow, so keep it to plain helpers and data, not business rules.
- Services still share the repository and one autoloader. The check keeps the code decoupled. It does not make the services separately deployable on its own.
- The check only sees PHP names. It does not catch two services agreeing on the same hard-coded value. The OpenAPI and AsyncAPI files and the end-to-end suites cover that side.
