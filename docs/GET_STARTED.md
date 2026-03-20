# Get Started — Semitexa

> Entry points: [AI quick brief](ai/GET_STARTED.md) · [Human entry](hm/GET_STARTED.md)  
> Also: [About Semitexa](../README.md) · [AI Reference](../AI_REFERENCE.md)

This is the **canonical install and run guide** for Semitexa.

The goal is not to impress you with setup steps. The goal is to get you from zero to a running app fast enough that you can feel the shape of the system.

If Semitexa makes sense, it should make sense early.

---

## Purpose

- Get a Semitexa app from zero to running with minimal ambiguity.
- Keep one canonical sequence for install, init, environment setup, and startup.
- Remove friction before architecture even begins.

---

## What You Need

- **Composer**
- **Docker Compose**

You do not need PHP installed on the host for the standard local install flow.

Semitexa runs on Swoole inside Docker. That is the supported runtime path.

---

## When To Use This

- Bootstrapping a brand new Semitexa project.
- Running an existing Semitexa project after clone/install.
- Verifying that install instructions are still correct.

---

## Canonical Steps

### 1. Get the project

- **Existing project:** clone the repository and continue.
- **New project:** use the official installer:

```bash
curl -fsSL https://semitexa.com/install.sh | bash
```

Or choose a project directory explicitly:

```bash
curl -fsSL https://semitexa.com/install.sh | bash -s my-project
```

This creates the project locally with the Semitexa scaffold already in place.

### 2. Move into the project directory

Change into the created project folder before starting the app.

```bash
# If you used the installer:
cd my-project

# If you cloned an existing repo:
cd <cloned-repo>
```

### 3. Prepare the environment

```bash
cp .env.example .env
```

Default HTTP port is **9502**. Change `SWOOLE_PORT` in `.env` if needed.

### 4. Start the application

```bash
bin/semitexa server:start
```

Open **http://localhost:9502** in your browser by default.

To stop:

```bash
bin/semitexa server:stop
```

---

## Rules And Constraints

- Run Semitexa via Docker, not `php server.php` on the host.
- The canonical local install path is `curl -fsSL https://semitexa.com/install.sh | bash`.
- Treat project-level docs and package docs as canonical sources; avoid inventing alternate install flows.

The point of these constraints is simple: fewer unofficial paths means fewer confusing failures later.

---

## If Something Goes Wrong

- **Project files were not created correctly**: re-run the official installer from a clean parent directory.
- **Port or runtime confusion**: read `vendor/semitexa/core/docs/RUNNING.md`.
- **Need the first page after install**: continue with [MINIMAL_PAGE.md](MINIMAL_PAGE.md).

If you reached a running app, move on quickly. The next page is where Semitexa usually stops sounding abstract and starts feeling mechanical in the best possible way.

---

## Mapping

| Goal | Document or command |
|------|----------------------|
| Why Semitexa | [README.md](../README.md) · [AI_REFERENCE.md](../AI_REFERENCE.md) |
| First HTML page with Twig | [MINIMAL_PAGE.md](MINIMAL_PAGE.md) |
| Add routes | `vendor/semitexa/core/docs/ADDING_ROUTES.md` |
| Run / Docker / ports / logs | `vendor/semitexa/core/docs/RUNNING.md` |
| Service contracts / DI | `vendor/semitexa/core/docs/SERVICE_CONTRACTS.md` · `bin/semitexa contracts:list --json` |

---

## AI Quick Brief

1. Get project.
2. `curl -fsSL https://semitexa.com/install.sh | bash -s my-project`
3. `cd my-project`
4. `cp .env.example .env`
5. `bin/semitexa server:start`

For the first route with Twig, use [MINIMAL_PAGE.md](MINIMAL_PAGE.md).
