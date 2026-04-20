# Semitexa Docs Map

This folder uses a **single-source documentation model**:

- `docs/GET_STARTED.md` and `docs/MINIMAL_PAGE.md` are the canonical guides.
- `docs/AI_BEST_PRACTICES.md` is the canonical practical guide for AI-friendly Semitexa implementation patterns.

If a topic changes, update the canonical guide first. Do not reintroduce audience-specific content branches for the same topic.

## Public Docs Flow

- [Why Semitexa](../README.md)
- [Get Started](GET_STARTED.md)
- [Build With Semitexa](BUILD.md)
- [Reference](REFERENCE.md)
- [AI](AI.md)
- [Site Map](SITE_MAP.md)

## Workspace and Framework Documentation

Monorepo-level architecture, cross-package policies, toolchain reference, and technical-debt tracking live under [`workspace/`](workspace/). These docs are the canonical home for material that used to live in root `./docs/`.

- [Workspace index](workspace/README.md)
- [Documentation ownership](workspace/DOCUMENTATION_OWNERSHIP.md)
- [Architecture](workspace/ARCHITECTURE.md)
- [DI — One Way](workspace/DI_ONE_WAY.md)
- [PHPStan workflow](workspace/PHPSTAN.md)
- [Technical debt](workspace/TECHNICAL_DEBT.md)
