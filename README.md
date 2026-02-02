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
│   ├── AI_QUICK_START.md      # 5 key rules for AI (read first if project AI_ENTRY is outdated)
│   ├── RECOMMENDED_STACK.md   # For HTML apps: core + core-frontend only; no custom renderer
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

## New project setup

**One command:** after `composer require syntexa/core`, the Composer plugin runs `syntexa init` automatically if the project has no `server.php` yet.

```bash
mkdir my-app && cd my-app
composer init -n
composer require syntexa/core
# Plugin runs init automatically; then:
cp .env.example .env
php server.php
```

You may be prompted to allow the plugin: `composer config allow-plugins.syntexa/core true`.

To scaffold manually (or overwrite): `vendor/bin/syntexa init` (use `--force` to overwrite, `--dir=/path` for another directory). The command creates: `bin/`, `public/`, `src/modules/`, `src/infrastructure/`, `var/`, `.env.example`, `server.php`, `bin/syntexa`, `.gitignore`.

## Upgrading (existing projects)

After upgrading **syntexa/core** in an existing project, you can refresh the AI entry point and project docs so AI sees current conventions (var/docs, ADDING_ROUTES, Twig for HTML):

```bash
bin/syntexa init --only-docs
```

This updates `AI_ENTRY.md`, `README.md`, and `docs/` (CONVENTIONS, DEPENDENCIES, RUNNING, ADDING_ROUTES stub) from the framework template. Use `--force` to overwrite existing files. If you prefer not to overwrite, update `AI_ENTRY.md` manually from the template in vendor/syntexa/core (InitCommand) or copy the new conventions (var/docs, ADDING_ROUTES link, Twig for HTML) from the latest docs.

## Quick links (shared docs)

| Topic | Location |
|-------|----------|
| **AI quick start** (5 key rules) | [AI_QUICK_START.md](AI_QUICK_START.md) — read first if project AI_ENTRY is outdated |
| **AI quick reference** | [AI_REFERENCE.md](AI_REFERENCE.md) |
| **Adding new pages/routes** | Start with [core/docs/ADDING_ROUTES.md](../core/docs/ADDING_ROUTES.md) (how to create a module). For **HTML pages** use [AI_REFERENCE.md](AI_REFERENCE.md) or [guides/CONVENTIONS.md](guides/CONVENTIONS.md) (Response DTO, Twig, templates) — do not render HTML manually in the Handler. |
| **Architecture** | [architecture/ARCHITECTURE.md](architecture/ARCHITECTURE.md) |
| **Overlay architecture** | [architecture/OVERLAY_ARCHITECTURE.md](architecture/OVERLAY_ARCHITECTURE.md) |
| **Conventions** | [guides/CONVENTIONS.md](guides/CONVENTIONS.md) |
| **Examples** | [guides/EXAMPLES.md](guides/EXAMPLES.md) |
| **Recommended stack (HTML)** | [RECOMMENDED_STACK.md](RECOMMENDED_STACK.md) — core + core-frontend only; do not implement your own Twig renderer |

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

1. **Start with** the app’s `AI_ENTRY.md` (e.g. in project root); it points here. If the project was created before recent framework updates, read [AI_QUICK_START.md](AI_QUICK_START.md) in vendor for the 5 key rules (routes, HTML/Twig, var/docs, no vendor patches, links).
2. **Adding new pages/routes** — first read [core/docs/ADDING_ROUTES.md](../core/docs/ADDING_ROUTES.md) (how to create a module). For **HTML pages** use **syntexa/core-frontend** only (Twig, LayoutRenderer); do not implement your own Twig renderer in the project. See [AI_REFERENCE.md](AI_REFERENCE.md) or [guides/CONVENTIONS.md](guides/CONVENTIONS.md).
3. **Module structure** — Before creating or changing module layout, read [AI_REFERENCE.md](AI_REFERENCE.md) → **Module Structure** (Standard Module Layout: Application/Input/, Application/Output/, Application/Handler/Request/, Application/View/templates/). Do not rely only on core/docs or project docs for folder names.
4. **Shared docs** — read [AI_REFERENCE.md](AI_REFERENCE.md), then [architecture/](architecture/) and [guides/](guides/) as needed.
5. **Per-package** — for attributes use [core/docs/attributes/](../core/docs/attributes/); for ORM use [orm/docs/](../orm/docs/); for dev-tools/window-manager use their READMEs.
6. **Package map** — use the table above to see which packages exist and what to install for a given feature.
7. **Working directory** — use the application’s `var/docs/` for temporary or intermediate files (plans, notes, drafts). Content is not committed (`.gitignore`). Examples: refactor plan → `var/docs/refactor-plan.md`; exploration notes → `var/docs/exploration-notes.md`; draft doc → `var/docs/draft-xyz.md`. Use `var/docs` for any intermediate output so that `docs/` and the project root stay clean.

## Contributing

When adding or moving documentation:

1. **Shared** framework topics (architecture, conventions) → `packages/syntexa/docs/`.
2. **Package-specific** topics (attributes, ORM, a given module) → `packages/syntexa/<package>/docs/` or package `README.md`.
3. Write in English first.
4. Update this README if you add new sections or packages to the map.
