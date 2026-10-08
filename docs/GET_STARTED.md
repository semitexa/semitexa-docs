# Get Started — Semitexa

> Also: [AI Reference](../AI_REFERENCE.md) · [A minimal working page](MINIMAL_PAGE.md)

This is the **canonical install and run guide** for Semitexa.

The goal is not to impress you with setup steps. The goal is to get you from zero to a running app fast enough that you can feel the shape of the system.

If Semitexa makes sense, it should make sense early.

---

## What You Need

- **Docker with Compose v2** (`docker compose version` must print v2).
- A user that can run Docker without `sudo` (on Linux: a member of the `docker` group).

That is all. You do **not** need PHP or Composer on the host: the runtime (PHP 8.4 + Swoole 6) and Composer both run inside containers.

---

## Quickstart

Run this from the **parent** directory of where the project should live:

```bash
curl -fsSL https://semitexa.com/install.sh | bash -s my-project
cd my-project
bin/semitexa server:start
```

- The first `server:start` takes about a minute: Composer installs the dependencies in the `setup` container.
- `server:start` prints the URL when the app is up. The default is **http://localhost:9502**. If 9502 is already taken, the installer picks a free port in 9501–9599 and writes it to `.env` as `SWOOLE_PORT`, so trust the printed URL over the default.
- The page you see is the starter `Hello` module, served at `/` from `src/modules/Hello/`.

Then create the database tables:

```bash
bin/semitexa orm:sync
```

The installed stack runs MySQL, Redis and NATS next to the app. `orm:sync` creates the tables from the `#[FromTable]` resources the installed packages declare. (Related commands: `orm:diff`, `orm:status`, `orm:seed`.)

To stop:

```bash
bin/semitexa server:stop
```

### What the installer does outside the project directory

- It registers the app in `~/.semitexa/router/registry/apps/<uuid>.env`, a small port registry that keeps two Semitexa projects on one machine from claiming the same port.
- It can **optionally** set up a local `.test` domain. That step needs `sudo`, changes your system DNS (systemd-resolved, `/etc/resolv.conf`) or `/etc/hosts`, and starts shared router containers that bind host port 80. The installer asks before doing it — answer `n` to skip. Passing `--start` to the installer skips all prompts and registers the default domain, so leave `--start` out if you do not want that. You can add a domain later; see the hub page `get-started/local-domain`.

---

## Next Steps

1. **Check the setup:** `bin/semitexa self-test` checks Docker, Compose, the project files and the CPU architecture.
2. **See the routes:** `bin/semitexa routes:list`.
3. **Build your first page:** [MINIMAL_PAGE.md](MINIMAL_PAGE.md) — a module with a payload, handler, resource and Twig template, starting from `bin/semitexa make:page`.
4. **Read the logs:** `bin/semitexa logs:app`.
5. **Add a package** that is not part of `semitexa/ultimate` (for example `semitexa/graphql`): Composer runs inside the app image, so run `docker compose run --rm --no-deps --user "$(id -u):$(id -g)" app composer require semitexa/graphql`, then `bin/semitexa server:restart`.

---

## Existing project

Clone the repository, `cd` into it and run `bin/semitexa server:start`. If the project has no `vendor/` yet, `bin/semitexa install` runs `composer install` inside the app container first.

Create `.env` only for local overrides (for example a different `SWOOLE_PORT`); `.env.default` is the committed baseline. Do not commit `.env`.

---

## Rules And Constraints

- Run Semitexa through `bin/semitexa` and Docker, not `php server.php` on the host.
- The canonical install path is `curl -fsSL https://semitexa.com/install.sh | bash -s my-project`.
- New code goes into modules under `src/modules/` — see [MINIMAL_PAGE.md](MINIMAL_PAGE.md).

The point of these constraints is simple: fewer unofficial paths means fewer confusing failures later.

---

## If Something Goes Wrong

- **`docker` needs `sudo`**: add your user to the `docker` group (`sudo usermod -aG docker $USER`), then log out and back in.
- **The project directory already exists**: the installer refuses to overwrite it. Pick another name or remove the directory.
- **Nothing answers on the port**: use the URL `server:start` printed, or read `SWOOLE_PORT` in `.env`.
- **Errors in the browser**: `bin/semitexa logs:app` shows the application log.

---

## Mapping

| Goal | Document or command |
|------|----------------------|
| Why Semitexa | [AI_REFERENCE.md](../AI_REFERENCE.md) |
| First HTML page with Twig | [MINIMAL_PAGE.md](MINIMAL_PAGE.md) |
| Add routes | the hub page `routing/adding-routes` |
| Install details, verification | the hub page `get-started/installation` |
| Local `.test` domain | the hub page `get-started/local-domain` |
| Database schema | `bin/semitexa orm:sync` · the hub page `data/schema-sync` |
| Service contracts / DI | the hub page `di/contract-resolution` · `bin/semitexa contracts:list --json` |

---

## AI Quick Brief

1. `curl -fsSL https://semitexa.com/install.sh | bash -s my-project`
2. `cd my-project`
3. `bin/semitexa server:start` — prints the URL (default `http://localhost:9502`).
4. `bin/semitexa orm:sync`
5. First page: [MINIMAL_PAGE.md](MINIMAL_PAGE.md).
