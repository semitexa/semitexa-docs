---
id: get-started/local-domain
section: get-started
slug: local-domain
title: Local Domain
summary: Register .test domains through the built-in local-domain helper instead of relying on ad hoc host setup.
order: 20
locale: en
status: canonical
verified_against: 2026.10.03.1952
keywords:
  - TENANCY_BASE_DOMAIN
  - bin/semitexa local-domain:add
  - local-domain:list
  - server:restart
demo_preview: get-started-playbook
recommended_runtime_panels:
  - practical-rules
---
# Local Domain

Serious tenancy work should happen on real local hostnames, not on endless `localhost` tabs.

A local domain is **optional**. A project works on `http://localhost:<port>` without one.

## What it changes on your machine

Registering a `.test` domain reaches outside the project. Read this before you run it:

- **It needs `sudo`.** You will be asked for your password.
- **It changes name resolution.** In the default `dns` mode, on a system running systemd-resolved, it writes `/etc/systemd/resolved.conf.d/semitexa-router.conf` (sending `*.test` lookups to the router's DNS on `127.0.0.1:5553`), may set `DNSStubListener=yes` in `/etc/systemd/resolved.conf`, restarts systemd-resolved and points `/etc/resolv.conf` at the systemd stub resolver. It asks before each of these when it has a terminal. In `hosts` mode it appends `127.0.0.1 <domain> # semitexa-router` lines to `/etc/hosts` instead. `bin/semitexa local-domain:mode` shows or switches the mode.
- **It binds host port 80.** The shared router containers (a proxy, plus a DNS container in `dns` mode) publish port 80, so nothing else on the machine can be listening there.
- It records the domain in `~/.semitexa/router/registry/`, shared by every Semitexa project on the machine.

`bin/semitexa local-domain:remove <domain.test>` removes a domain, including its `/etc/hosts` line.

## Canonical flow

1. Choose one stable `.test` base domain. Only `.test` is accepted.
2. Put it in `.env` as `TENANCY_BASE_DOMAIN`, so the app builds tenant hosts and absolute URLs from it.
3. Register the main host and any tenant hosts through the Semitexa helper.
4. Restart the runtime so DNS, proxy, and environment agree on one shape.
5. Open the real host in the browser.

## Commands

In `.env`:

```dotenv
TENANCY_BASE_DOMAIN=semitexa.test
```

Then:

```bash
bin/semitexa local-domain:add semitexa.test
bin/semitexa local-domain:add acme.semitexa.test
bin/semitexa local-domain:list
bin/semitexa server:restart
```

Setting `TENANCY_BASE_DOMAIN` as a shell variable does nothing: the app container reads it from `.env`, and only picks up a change after `server:restart`.

## Rules

- prefer one memorable `.test` base domain per project
- use the CLI helper instead of manual host drift
- restart after meaningful DNS or environment changes
- verify tenant hosts in the browser, not only the raw port

## Why this matters

Tenancy, cookie scope, domain routing, and absolute URL behavior become much easier to reason about when local development already behaves like a real product host.
