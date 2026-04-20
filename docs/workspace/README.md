# Semitexa Workspace Documentation

This directory holds **monorepo-level and cross-package documentation** for the Semitexa framework. It is part of `packages/semitexa-docs`, the single official documentation source for Semitexa.

Framework-level, cross-cutting material that does not belong to any one package lives here.

## What belongs here

- Repository-wide architecture and design decisions
- Cross-package policies (documentation ownership, DI rules, testing strategy)
- Toolchain and workflow reference (PHPStan, release flow)
- Technical debt tracking and audit reports
- Workspace-oriented contributor guides

## What does not belong here

- **Package internals** — those live in `packages/<package>/docs/`.
- **Onboarding and public-facing product docs** — those live alongside this directory in `packages/semitexa-docs/docs/` (the non-`workspace/` surface).
- **Drafts, research, working notes, release prep** — those live in `var/docs/` (scratch, not canonical).

## Index

### Policy and ownership

- [DOCUMENTATION_OWNERSHIP.md](DOCUMENTATION_OWNERSHIP.md) — where each type of Semitexa documentation belongs. Start here before moving or creating docs.

### Architecture

- [ARCHITECTURE.md](ARCHITECTURE.md) — framework architecture overview: Swoole server, modules, request lifecycle, DI.
- [DI_ONE_WAY.md](DI_ONE_WAY.md) — canonical DI rule: property injection on container-managed classes; constructors allowed, constructor injection is not.
- [MODULE_STRUCTURE.md](MODULE_STRUCTURE.md) — project-level module layout wrapper; defers to `packages/semitexa-core/docs/MODULE_STRUCTURE.md` for the canonical rules.

### Workflow and tooling

- [PHPSTAN.md](PHPSTAN.md) — PHPStan baseline discipline, strict mode, helper scripts.
- [TESTING.md](TESTING.md) — testing entry guide; see also `packages/semitexa-testing/` for the full toolkit.
- [EVENTS_TESTING.md](EVENTS_TESTING.md) — testing event-driven (async) handling with NATS.
- [DEPLOYMENT.md](DEPLOYMENT.md) — production deployment on Swoole, Docker, and Supervisor.

### Technical debt and audits

- [TECHNICAL_DEBT.md](TECHNICAL_DEBT.md) — current known debt and recommended order of work.
- [technical-design/](technical-design/) — targeted audit reports (ORM, payload testing, tenancy, testing, WM improvement).

## Cross-links to package docs

For anything owned by a specific package, follow the link into that package:

- `packages/semitexa-core/docs/` — request lifecycle, attributes, routing, DI runtime.
- `packages/semitexa-testing/docs/` — payload testing toolkit.
- `packages/semitexa-ledger/docs/` — NATS event ledger and command bus.
- `packages/semitexa-docs/docs/` — the public/product-facing guides (one level up from here).
