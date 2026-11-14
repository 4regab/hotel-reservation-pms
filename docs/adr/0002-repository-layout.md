# 0002. Repository layout

Status: Accepted
Date: 2026-11-15

## Context

The repository holds four services (portal, booking, room operations, billing) and one shared library. They sat loose at the repository root, next to config files, scripts and tests. Nothing in the layout said which folders were deployable services and which were tooling.

## Options considered

1. **Leave it.** No work, but the root stays crowded and the service boundary is not visible in the tree.
2. **`services/` and `packages/`.** Reads clearly, but no reference project I checked uses these names.
3. **Everything deployable under `src/`.** Services side by side, with the shared code next to them.

## Evidence

Checked on 2026-11-15, from the public repositories' folder listings:

- [dotnet/eShop](https://github.com/dotnet/eShop) (Microsoft's reference microservices app): every service and the `Shared/` project sit under `src/`. Tests are in a root `tests/` and end-to-end tests in a separate `e2e/`.
- [GoogleCloudPlatform/microservices-demo](https://github.com/GoogleCloudPlatform/microservices-demo): services are `src/<name>/`, each self-contained and with its own `src/` or tests inside. `docs/` is flat with descriptive file names.
- [php-pds/skeleton](https://github.com/php-pds/skeleton), the PHP package standard: root folders are named `bin/`, `config/`, `docs/`, `public/`, `resources/`, `src/` and `tests/`.

## Decision

Option 3.

```
src/portal  src/booking  src/roomops  src/billing   the four services, each with its own src/ public/ bin/ migrations/
src/shared                                            code every service may use (PHP namespace Lib)
scripts/   tests/unit   tests/e2e   docs/ (flat, plus docs/adr/)
```

- The PHP namespaces do not change (`Portal\`, `Booking\`, `RoomOps\`, `Billing\`, `Lib\`). Only the folders they map to changed, in `autoload.php`.
- Files were moved with `git mv`, so their history is kept.
- Generated contracts (`docs/openapi.yaml`, `docs/asyncapi.yaml`) stay in `docs/`. They are output built from the routes, not the source of the contract.

## Consequences

- The tree shows what is deployable (`src/*`) and what is tooling (everything else).
- Every service has a doubled `src/` (`src/billing/src/`). Google's demo does the same, and it keeps each service self-contained.
- Not adopted: Composer. The project has no third-party runtime packages, and the README start-up stays one command block. This can be revisited if services are deployed separately.
- Not adopted: ownership, security-policy and conduct files. This is a single-maintainer project.
