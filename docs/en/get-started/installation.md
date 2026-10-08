---
id: get-started/installation
section: get-started
slug: installation
title: Installation
summary: Create the project with the one-line installer, start it, create the database tables, and know what the installer changes outside the project.
order: 10
locale: en
status: canonical
verified_against: 2026.10.03.1952
keywords:
  - install.sh
  - orm:sync
  - bin/semitexa
  - .env.default
  - .env
  - self-test
  - routes:list
demo_preview: get-started-playbook
recommended_runtime_panels:
  - install-checklist
  - boot-verification
---
# Installation

Installation in Semitexa should end with a running runtime and a trustworthy operator shell, not with half-finished setup notes.

## Prerequisites

- Docker with Compose v2, and a user that can run `docker` without `sudo` (on Linux: the `docker` group).
- No PHP and no Composer on the host. PHP 8.4 + Swoole 6 and Composer run inside the containers.

## Canonical flow

Run from the parent directory of where the project should live:

```bash
curl -fsSL https://semitexa.com/install.sh | bash -s my-project
cd my-project
bin/semitexa server:start
bin/semitexa orm:sync
```

1. The installer creates `my-project/` with the Semitexa scaffold and picks the app port.
2. `server:start` builds the image and starts the stack. The first run takes about a minute because Composer installs the dependencies in the `setup` container. When the app is up it prints its URL.
3. Open that URL. The default is `http://localhost:9502`; if 9502 was busy at install time, the installer chose a free port in 9501–9599 and wrote it to `.env` as `SWOOLE_PORT`.
4. `orm:sync` creates the database tables. The installed stack runs MySQL, Redis and NATS next to the app.

`.env.default` is the committed baseline. Create or edit `.env` only for overrides on this machine, and do not commit it.

## What the installer changes outside the project

- **Port registry.** The app is registered in `~/.semitexa/router/registry/apps/<uuid>.env`, so Semitexa projects on the same machine never claim the same port. `bin/semitexa local-app:list` shows the entries; `local-app:remove` deletes one.
- **Local domain (optional).** A `<name>.test` domain is opt-in: the installer asks (default **No**), and a non-interactive or `--start` run never sets one up unless you pass `--local-domain`. Accepting it needs `sudo`, changes system DNS (systemd-resolved / `/etc/resolv.conf`) or `/etc/hosts`, and starts shared router containers that bind host port 80. See [Local Domain](local-domain.md) to add one later.

## Verify the install

```bash
bin/semitexa self-test
bin/semitexa routes:list --json
```

- `self-test` checks that Docker and Compose are installed, that the project files exist (`docker-compose.yml`, `Dockerfile`, `server.php`, `.env.default`, `composer.json`, `vendor/bin/semitexa`), and which CPU architecture you are on (AMD64 and ARM64 are supported). It also says whether the app container is running. It does not send a request to the app.
- `routes:list` shows the discovered routes; the starter `Hello` module answers `/`.
- Load the printed URL in a browser.
- `bin/semitexa logs:app` shows the application log when something fails.

## Why this matters

If the first boot path is ambiguous, every later problem becomes harder to diagnose. Semitexa should feel operationally legible from the first hour.
