# Get Started — Semitexa (for AI Agents)

> 👤 **For humans:** A simpler, narrative version of this guide is in [Get Started (for humans)](../hm/GET_STARTED.md).  
> **Also:** [About Semitexa](../../README.md) (vision) · [AI Reference](../../AI_REFERENCE.md) (philosophy and rules for agents).

This document gives the **minimal steps to install and run a Semitexa application**. Use it when you need to bootstrap a project or verify that the framework runs. For adding routes, contracts, or modules, use the linked docs below.

---

## Purpose

- Get a Semitexa app from zero to running with the least possible steps.
- Avoid ambiguity so agents and scripts can reproduce the same result.

---

## Prerequisites

- **PHP:** ^8.4 (see project or package `composer.json` / `composer.lock`).
- **Composer:** to install dependencies.
- **Docker & Docker Compose:** the only supported way to run the app (Swoole runs inside the container).

---

## Scope / When to use

- Setting up a **new** Semitexa project (from scratch or from a template).
- Setting up an **existing** Semitexa project (clone + install + init + run).
- Verifying that installation and run steps are correct.

---

## Steps (minimal)

### 1. Get the project

- **Option A — Existing project (e.g. semitexa.dev):** clone the repo, then go to step 2.
- **Option B — New project:** create a directory, run `composer init`, then add the meta-package (it pulls in core and everything needed):

  ```bash
  composer require semitexa/ultimate
  ```

  Then go to step 2.

### 2. Install dependencies

```bash
composer install
```

(After this, `bin/semitexa` and `vendor/semitexa/` are available. The core plugin may run `registry:sync` automatically.)

### 3. Initialize project files (if missing)

If the project has no `docker-compose.yml`, `.env.example`, or `server.php`:

```bash
bin/semitexa init
```

This writes Docker, env example, entry script, and optional docs from the core package templates.

### 4. Environment

```bash
cp .env.example .env
```

Edit `.env` if needed (e.g. `SWOOLE_PORT`). Default port is **9502**.

### 5. Run the application

```bash
bin/semitexa server:start
```

This starts the stack via Docker Compose. The app is available at **http://0.0.0.0:9502** (or the port set in `.env`).

**Stop:**

```bash
bin/semitexa server:stop
```

---

## Rules & constraints

- **Run only via Docker.** Do not run `php server.php` on the host as the primary way to run the app; the supported way is `bin/semitexa server:start` (which uses Docker).
- If you see "docker-compose.yml not found", run `bin/semitexa init` (or ensure the project was generated from a template that includes it).
- Do not add or change Composer dependencies or root-level directories without explicit user approval; same for creating docs or new top-level folders.

---

## Mapping (where to read more)

| Goal | Document or command |
|------|----------------------|
| Why Semitexa (vision, goals) | [README.md](../../README.md) · [AI_REFERENCE.md](../../AI_REFERENCE.md) |
| **First page with Twig** (minimal route + Payload + Handler + template) | [A minimal working page (AI)](MINIMAL_PAGE.md) · [for humans](../hm/MINIMAL_PAGE.md) |
| Add new pages / routes | `vendor/semitexa/core/docs/ADDING_ROUTES.md` (or package path `packages/semitexa-core/docs/ADDING_ROUTES.md`) |
| Run / Docker / ports / logs | `vendor/semitexa/core/docs/RUNNING.md` |
| Service contracts, DI, bindings | `vendor/semitexa/core/docs/SERVICE_CONTRACTS.md` · `bin/semitexa contracts:list --json` |
| Project entry point for agents | Project root `AI_ENTRY.md` (created by `semitexa init`) |

---

## Summary for agents

1. **Get project** → clone or `composer require semitexa/ultimate`.  
2. **Install** → `composer install`.  
3. **Init if needed** → `bin/semitexa init`.  
4. **Env** → `cp .env.example .env`.  
5. **Run** → `bin/semitexa server:start` (Docker). App on port 9502 by default.

For a **first page with Twig** (one route, Payload, Handler, template), use [MINIMAL_PAGE.md](MINIMAL_PAGE.md). For anything else (routes, modules, contracts), use the **Mapping** table above and the referenced docs.
