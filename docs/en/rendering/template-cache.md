---
id: rendering/template-cache
section: rendering
slug: template-cache
title: Twig Template Cache
summary: How the Twig cache behaves under long-running workers and when you need to clear it.
order: 220
locale: en
status: canonical
verified_against: 2026.10.03.1952
keywords:
  - Twig
  - cache
  - template
---

# Twig Template Cache

The **only supported way** to run a Semitexa application is via **Docker**, driven by `bin/semitexa` from the project root on the host.

- **Start:** `bin/semitexa server:start` runs `docker compose up` with `docker-compose.yml` plus the overlays your `.env` asks for: `docker-compose.mysql.yml` when `DB_DRIVER` is set, `docker-compose.redis.yml` when `REDIS_HOST` is set, `docker-compose.nats.yml` when `EVENTS_ASYNC=1`, `docker-compose.ollama.yml` when `LLM_PROVIDER=ollama`, and `docker-compose.override.yml` if it exists.
- **Stop:** `bin/semitexa server:stop` stops every service from all of those files.
- **Restart the app only:** `bin/semitexa server:restart app`.
- **Logs:** `bin/semitexa logs:app` for the application log; `docker compose logs -f app` for the container output.

`docker-compose.yml` defines four services: `app` (the Swoole server, `php server.php`, listening on `SWOOLE_PORT`, default 9502), `setup` (runs Composer before the app starts), and `scheduler` and `cli`, which only start when their Compose profile (`demo`, `cli`) is enabled. Do not run `php server.php` on the host as the primary way to run the app.

`bin/semitexa` exists only on the host. Inside a container the same console is `php vendor/bin/semitexa`, for example `docker compose exec app php vendor/bin/semitexa cache:clear`. Any command `bin/semitexa` does not handle itself is forwarded to that console in the `app` container.

## Twig template cache

Twig compiles templates into `var/cache/twig/`, and every Swoole worker also keeps the compiled templates it has loaded in memory for its whole life. So an edited template can stay invisible, or show up in some responses and not others, until the workers are cycled.

- **CLI (recommended):** `bin/semitexa cache:clear` clears Twig and the other framework caches and cycles the running workers. Add `--twig` to clear only the Twig cache, or `--no-reload` to leave the workers alone.
- **Permission denied:** the cache directory is created by the container, often as root, so a clear that runs as your host user can fail. `bin/semitexa cache:clear --via-docker` runs the clear inside the app container, which can delete the files.
- **Simplest reset:** `bin/semitexa server:restart app` starts fresh workers.

The framework also uses a writable fallback (system temp) when `var/cache/twig` is not writable, so the app keeps working; clearing the cache is only needed when you change templates or template paths and want to avoid stale compiled files.
