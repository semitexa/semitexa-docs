# Semitexa Workspace Documentation

This directory holds **monorepo-level and cross-package documentation** for the Semitexa framework. It is part of `packages/semitexa-docs`, the single official documentation source for Semitexa.

Framework-level, cross-cutting material that does not belong to any one package lives here.

## What belongs here

- Repository-wide architecture and design decisions
- Cross-package policies (documentation ownership, DI rules, testing strategy)
- Toolchain and workflow reference (PHPStan, release flow)
- Technical debt tracking and audit reports
- Workspace-oriented contributor guides

- **[INFORMATION_ARCHITECTURE.md](INFORMATION_ARCHITECTURE.md)** — where a page goes, the page contract, and which contracts are reference at all.

- **`proposals/`**, **`audits/`**, **`technical-design/`** — designs not built, reviews of code on a date, and designs that shipped. Each page says which it is on its first line.

## What does not belong here

- **Package internals** — those live alongside this directory in `packages/semitexa-docs/docs/`, filed by subject. A package no longer carries its own `docs/` prose; see [DOCUMENTATION_OWNERSHIP.md](DOCUMENTATION_OWNERSHIP.md).
- **Onboarding and public-facing product docs** — those live alongside this directory in `packages/semitexa-docs/docs/` (the non-`workspace/` surface).
- **Drafts, research, working notes, release prep** — those live in `var/docs/` (scratch, not canonical).

## Index

### Policy and ownership

- [DOCUMENTATION_OWNERSHIP.md](DOCUMENTATION_OWNERSHIP.md) — where each type of Semitexa documentation belongs. Start here before moving or creating docs.

### Architecture

- [ARCHITECTURE.md](ARCHITECTURE.md) — framework architecture overview: Swoole server, modules, request lifecycle, DI.
- [DI_ONE_WAY.md](DI_ONE_WAY.md) — canonical DI rule: property injection on container-managed classes; constructors allowed, constructor injection is not.
- [MODULE_STRUCTURE.md](MODULE_STRUCTURE.md) — project-level module layout wrapper; defers to the hub page `get-started/module-structure` for the canonical rules.

### Workflow and tooling

- [GIT_FLOW.md](GIT_FLOW.md) — how work moves through the framework's repositories: `develop` only, no feature branches, `develop` → `master` pull requests.
- [PHPSTAN.md](PHPSTAN.md) — PHPStan baseline discipline, strict mode, helper scripts.
- [TESTING.md](TESTING.md) — testing entry guide; see also `packages/semitexa-testing/` for the full toolkit.
- [EVENTS_TESTING.md](EVENTS_TESTING.md) — testing event-driven (async) handling with NATS.
- [DEPLOYMENT.md](DEPLOYMENT.md) — production deployment on Swoole, Docker, and Supervisor.

### Technical debt and audits

- [technical-design/](technical-design/) — targeted audit reports and follow-up recommendations (ORM, payload testing, tenancy, testing, WM improvement).

## Cross-links to package docs

Package documentation now lives in this hub: the public guides are under `packages/semitexa-docs/docs/en/` (served at https://semitexa.com/docs), with one page per subject. A package's own `docs/` folder keeps only what is specific to that repository:

- `packages/semitexa-core/docs/` — `README.md` (which hub page covers which core subject) and `RELEASE_NOTES.md`.
- `packages/semitexa-ledger/docs/adr/` — architecture decision records for the NATS event ledger.
- `packages/semitexa-docs/docs/` — the public/product-facing guides (one level up from here).

`semitexa-testing` has no `docs/` folder; its payload testing toolkit is documented on the hub page `testing/payload-contracts`.
