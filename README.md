# Syntexa Framework Documentation

> **Language:** English (original)  
> **Last updated:** 2024

This directory contains **shared** documentation for the Syntexa Framework. Package-specific docs live in each package under `packages/syntexa/<package>/docs/` (or package `README.md`).

## Structure

```
packages/syntexa/
├── docs/                      # Shared docs (you are here)
│   ├── README.md              # This file — index + package map
│   ├── AI_REFERENCE.md        # AI quick reference
│   ├── architecture/          # Architecture and overlay
│   └── guides/                # Conventions, examples
├── core/
│   └── docs/attributes/       # Attribute documentation
├── orm/
│   └── docs/                  # ORM docs (migrations, PostgreSQL, etc.)
├── dev-tools/
│   └── README.md              # Dev tools, AI endpoint
├── window-manager/
│   ├── README.md
│   └── EXAMPLE.md
└── ...                        # Other packages
```

## Quick links (shared docs)

| Topic | Location |
|-------|----------|
| **AI quick reference** | [AI_REFERENCE.md](AI_REFERENCE.md) |
| **Architecture** | [architecture/ARCHITECTURE.md](architecture/ARCHITECTURE.md) |
| **Overlay architecture** | [architecture/OVERLAY_ARCHITECTURE.md](architecture/OVERLAY_ARCHITECTURE.md) |
| **Conventions** | [guides/CONVENTIONS.md](guides/CONVENTIONS.md) |
| **Examples** | [guides/EXAMPLES.md](guides/EXAMPLES.md) |

## Package map (for AI and developers)

Use this table to see what the framework offers and which packages to add to your application.

| Package | Purpose | Docs | Install |
|---------|---------|------|--------|
| **core** | Request/response, attributes, discovery, container, CLI | [core/docs/attributes/](../core/docs/attributes/) | Required by framework (usually already in app) |
| **orm** | Entities, migrations, repositories, PostgreSQL, direct save/update/delete | [orm/docs/](../orm/docs/) | `composer require syntexa/module-orm` |
| **user-api** | HTTP API for auth/login | (see package) | `composer require syntexa/user-api` (or as per repo) |
| **user-domain** | Domain entities, AuthService | (see package) | As per repo |
| **user-frontend** | Login/dashboard UI, layouts, SSR | (see package) | As per repo |
| **core-frontend** | Layouts, Twig, slots | (see package) | As per repo |
| **dev-tools** | Metrics, logs, profiler, **AI endpoint** (`GET /dev-tools/api/ai`) | [dev-tools/README.md](../dev-tools/README.md) | `composer require syntexa/module-dev-tools` |
| **inspector** | Profiler, request watchers | (see package) | As per repo |
| **window-manager** | Window API, message bus, multi-window UI | [window-manager/README.md](../window-manager/README.md), [EXAMPLE.md](../window-manager/EXAMPLE.md) | As per repo |

**Note:** Exact composer package names may vary per repository (e.g. `syntexa/orm` vs `syntexa/module-orm`). Check each package’s `composer.json` for the correct name.

## For AI assistants

1. **Start with** the app’s `AI_ENTRY.md` (e.g. in project root); it points here.
2. **Shared docs** — read [AI_REFERENCE.md](AI_REFERENCE.md), then [architecture/](architecture/) and [guides/](guides/) as needed.
3. **Per-package** — for attributes use [core/docs/attributes/](../core/docs/attributes/); for ORM use [orm/docs/](../orm/docs/); for dev-tools/window-manager use their READMEs.
4. **Package map** — use the table above to see which packages exist and what to install for a given feature.
5. **Working directory** — use the application’s `var/docs/` for temporary or intermediate files.

## Contributing

When adding or moving documentation:

1. **Shared** framework topics (architecture, conventions) → `packages/syntexa/docs/`.
2. **Package-specific** topics (attributes, ORM, a given module) → `packages/syntexa/<package>/docs/` or package `README.md`.
3. Write in English first.
4. Update this README if you add new sections or packages to the map.
