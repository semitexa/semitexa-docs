---
id: runtime/deploying
section: runtime
slug: deploying
title: Deploying and Updating a Server
summary: Put a project on a fresh Ubuntu server with update:packages:bootstrap-remote, keep its semitexa/* packages current with update:packages:auto and a systemd timer, and know what a failed deploy rolls back and what no deploy does.
order: 100
locale: en
status: canonical
verified_against: 2026.10.20.0340
keywords:
  - update:packages:bootstrap-remote
  - update:packages:check
  - update:packages:auto
  - update:packages:materialize
  - SEMITEXA_REMOTE_DEPLOY_TARGETS
  - SEMITEXA_AUTO_DEPLOY_ENABLED
  - SEMITEXA_AUTO_DEPLOY_SOURCE
  - SEMITEXA_AUTO_DEPLOY_HEALTHCHECK_URL
  - SEMITEXA_AUTO_DEPLOY_RESTART_COMMAND
  - install-auto-deploy-systemd.sh
  - semitexa-auto-deploy.timer
  - var/log/deployments
---

# Deploying and Updating a Server

A Semitexa server runs the same Docker Compose stack as a development machine. `bin/semitexa server:start` builds the image and runs `docker compose up -d --build`. The `app` container runs Swoole and publishes `SWOOLE_PORT` on the host. There is no separate production runtime.

`semitexa/update` adds four commands for servers:

| Command | What it does |
|---|---|
| `update:packages:bootstrap-remote` | the first deployment of a project to a fresh Ubuntu host, over SSH |
| `update:packages:check` | shows the auto-deploy settings and any newer `semitexa/*` release; changes nothing |
| `update:packages:auto` | updates the `semitexa/*` packages when it is enabled and a newer release exists |
| `update:packages:materialize` | writes a `composer.json` with exact `semitexa/*` versions |

These commands move the framework packages. None of them brings your own code (`src/modules/`) to a server after the first deployment. Upgrading a project by hand, with data patches and `.new` scaffold files, is described in [Upgrading](../get-started/upgrading.md). Every option is listed in the [update command reference](../reference/commands-update.md).

## Where the commands run

`bin/semitexa` runs every command except `server:*` inside the `app` container: `docker compose exec` when the stack is up, a one-off container when it is not. Three things follow:

- Settings are read from the container's environment first, then from `.env.default` and `.env`. The container's environment is fixed when the container is created. After changing a value the container already had, recreate it with `bin/semitexa server:restart app`.
- The health check of `update:packages:auto` is fetched from inside the container; the systemd wrapper's, after its restart, from the host. A URL both can reach is the published one, such as `http://127.0.0.1:9502/health` with your `SWOOLE_PORT`.
- Only tools in the image are available. The scaffold image has `composer`, `git`, `tar` and the SSH client (`ssh`, `scp`). It has no Docker CLI and no `sshpass`.

`bin/semitexa` also runs the containers as the uid of whoever calls it, unless `SEMITEXA_UID` and `SEMITEXA_GID` are set (under rootless Docker, as `0`). Run every command on a server as the same user.

## First deployment: `update:packages:bootstrap-remote`

Configure the target in the project's `.env`:

```dotenv
SEMITEXA_REMOTE_DEPLOY_TARGETS=deploy@203.0.113.10
SEMITEXA_REMOTE_DEPLOY_PATH=/srv/semitexa/my-project
SEMITEXA_REMOTE_DEPLOY_SSH_PORT=22
SEMITEXA_REMOTE_DEPLOY_DOMAIN=my-project.example.com
```

| Setting | Default | Meaning |
|---|---|---|
| `SEMITEXA_REMOTE_DEPLOY_TARGETS` | none | comma-separated `user@host` targets. With none, the command asks for one and offers to append it to `.env`. With several, it asks which one. `--target` overrides it. |
| `SEMITEXA_REMOTE_DEPLOY_PATH` | `/srv/semitexa/<project directory name>` | the directory on the server. `--path` overrides it. |
| `SEMITEXA_REMOTE_DEPLOY_SSH_PORT` | `22` | a value outside 1–65535 falls back to `22` |
| `SEMITEXA_REMOTE_DEPLOY_DOMAIN` | none | written into the generated `.env` and into the deployment marker. Nothing else uses it. |
| `SEMITEXA_REMOTE_DEPLOY_SSH_IDENTITY_FILE` | none | a private key passed to `ssh` and `scp` as `-i`. Read like the other settings, `.env` included. |
| `SEMITEXA_REMOTE_DEPLOY_USE_PASSWORD` | `false` | `true` asks for the SSH password at once and offers no key (it needs `sshpass` locally); otherwise a key is tried first and the password is asked only when the server wants one. |

A host is letters, digits, dots and dashes, or an IPv6 address in brackets: `deploy@[2001:db8::10]`. A link-local address with a zone (`fe80::1%eth0`) is not accepted.

Then run it from a terminal:

```bash
bin/semitexa update:packages:bootstrap-remote
```

It needs an interactive TTY and `ssh`, `scp` and `tar` on the machine that runs it; password authentication also needs `sshpass`. Through `bin/semitexa` that machine is the `app` container, which has `ssh` and `scp`. An older project gets them with `bin/semitexa update`, which replaces a `Dockerfile` you never changed (a changed one gets `Dockerfile.new` to merge by hand), and `bin/semitexa server:restart`, which rebuilds the image. The container runs as your user's uid; when the image has no account for it, the command adds one, since `ssh` refuses to run without.

There is no `sshpass` and no SSH agent in the container, so authenticate with a key that has no passphrase. Name it with `SEMITEXA_REMOTE_DEPLOY_SSH_IDENTITY_FILE`; it must be readable inside the container and `chmod 600`, or `ssh` ignores it. Under the project directory, `.ssh/` is the place: it is in `.gitignore` and never packed into the artifact. Host keys the command accepts are kept in `var/ssh/known_hosts`, also never packed; the first connection to a server trusts its key (`StrictHostKeyChecking=accept-new`).

### What it does

On your side, in order:

1. Asks you to type `DEPLOY NEW SERVER`.
2. Checks for `ssh`, `scp` and `tar`.
3. Connects with a key (`BatchMode=yes`, `StrictHostKeyChecking=accept-new`). If the error looks like an authentication failure and `sshpass` is installed, it asks for the password.
4. Reads `/etc/os-release` on the server. Only Ubuntu 20.04 and newer is accepted. Ubuntu 20.04 and 22.04 get their own script; 24.04 and anything newer get the 24.04 script.
5. Inspects the deploy path. A path that holds a `.semitexa-deployment.json` marker, a `docker-compose.yml`, or any file at all counts as initialized and is refused. `--force-reinitialize` overrides that after one more confirmation.
6. Packs the project into a `.tar.gz`, leaving out `.git`, `.env`, `vendor`, `node_modules`, `.phpunit.cache`, `var/cache`, `var/log`, `var/run`, `.idea`, `.claude` and `.codex`. When `composer.release.json` exists (see [Exact versions](#exact-versions-updatepackagesmaterialize)), it packs a release instead: `vendor/` included, `packages/` and `composer.lock` left out, and that manifest as `composer.json`.
7. Prepares the server's `.env`: the file given with `--remote-env-file`, or a new one with `APP_ENV=prod`, `APP_DEBUG=0`, a random `APP_SECRET` and the domain.
8. Uploads the archive, the scripts and the `.env` to `/tmp/semitexa-remote-bootstrap-<time>-<random>` on the server, runs the bootstrap and verify scripts, and removes that directory.

On the server, the bootstrap script needs root or passwordless `sudo`. It:

- runs `apt-get update` and installs `ca-certificates`, `curl`, `jq`, `tar` and `git` (the 24.04 script adds `php-cli`);
- installs Docker with Docker's install script when `docker` is missing, and the Compose plugin when Compose is missing, then enables the Docker service;
- with `--force-reinitialize`, runs `docker compose down --remove-orphans` and deletes the deploy directory. Named volumes, such as the MySQL data volume, are not deleted;
- unpacks the archive and checks for `bin/semitexa`, `composer.json` and `docker-compose.yml`;
- copies the `.env` in, owned by `root` with mode `600`;
- runs `bin/semitexa install` when `vendor/` is missing, then `bin/semitexa server:start` and `cache:clear`, as root;
- writes `.semitexa-deployment.json` with the time, the scenario, the path and the domain.

Every script refuses a relative deploy path, one with `..` or `.` segments, and system directories such as `/`, `/srv` or `/var`. With `--force-reinitialize` it deletes the deploy path only when an earlier bootstrap made it (it holds `.semitexa-deployment.json`) or it is empty; any other directory is refused.

The verify script passes when the marker exists and the `app` container is running. It sends no HTTP request.

Once a target is chosen, every run, successful or not, writes `var/log/deployments/remote-bootstrap-<YmdHis>.json` on your side, with the steps completed and the reason it stopped.

### What it does not do

- It is for a first deployment only. Running it against a used path needs `--force-reinitialize`, which deletes that directory.
- It sets up no TLS, reverse proxy, DNS record or firewall. The app answers plain HTTP on `SWOOLE_PORT`.
- It puts only `APP_ENV`, `APP_DEBUG`, `APP_SECRET` and the domain into the server's `.env`. Everything else comes from `.env.default`, including database credentials. Pass production values with `--remote-env-file`.
- It does not install the auto-deploy timer.

Because the stack was started as root and `.env` is readable only by root, run `bin/semitexa` on that server as root.

## Keeping a server updated

### Settings

Auto-deploy is off until you turn it on in the server's `.env`:

```dotenv
SEMITEXA_AUTO_DEPLOY_ENABLED=true
SEMITEXA_AUTO_DEPLOY_SOURCE=packagist
SEMITEXA_AUTO_DEPLOY_HEALTHCHECK_URL=http://127.0.0.1:8080/health
```

| Setting | Default | Meaning |
|---|---|---|
| `SEMITEXA_AUTO_DEPLOY_ENABLED` | `false` | only `true` (any case) turns it on; `1` or `yes` leave it off |
| `SEMITEXA_AUTO_DEPLOY_CHANNEL` | `stable` | the only channel; any other value becomes `stable` |
| `SEMITEXA_AUTO_DEPLOY_SOURCE` | `packagist` | `packagist`, `private` or `mixed`; any other value becomes `packagist` |
| `SEMITEXA_AUTO_DEPLOY_PRIVATE_REPOSITORY_URL` | none | a git repository whose newest stable tag counts as a release (`private` and `mixed` only) |
| `SEMITEXA_AUTO_DEPLOY_HEALTHCHECK_URL` | none | fetched after the restart; anything but a 2xx answer fails the deploy; each request waits up to 10 seconds, and the wrapper tries for about a minute and a half |
| `SEMITEXA_AUTO_DEPLOY_RESTART_COMMAND` | none | a shell command run from the project root instead of the built-in restart |
| `SEMITEXA_AUTO_DEPLOY_HOME` | none | exported as `HOME` for the commands the deploy runs |
| `SEMITEXA_AUTO_DEPLOY_COMPOSER_HOME` | none | exported as `COMPOSER_HOME` |
| `SEMITEXA_AUTO_DEPLOY_GIT_SSH_COMMAND` | none | exported as `GIT_SSH_COMMAND` |
| `SEMITEXA_UPDATE_ACTOR` | `auto-deploy` | the actor written to the run journal |

The sources:

- `packagist` asks the public Composer registry for the newest stable version of each installed `semitexa/*` package. A package the registry does not know is skipped. When the registry does not answer, the lookup is tried once more, then reported as a warning.
- `private` runs `git ls-remote --tags` against the VCS repositories declared in `composer.json` for those packages, and against `SEMITEXA_AUTO_DEPLOY_PRIVATE_REPOSITORY_URL` when it is set.
- `mixed` does both, and the newer version of each package wins.

Installed versions are read from `vendor/composer/installed.json`, or from `composer.lock` when there is no `vendor/`. Packages installed from a path repository are listed separately and not checked for updates.

`/health` is answered by the server itself: `200` with `{"status":"ok", …}` from whichever worker takes the request. It does not check the database or which release is loaded.

### `update:packages:check`

```bash
bin/semitexa update:packages:check
bin/semitexa update:packages:check --json
```

It prints the settings, the version a deploy would move to, the reason, a table of package updates, and any packages skipped because they come from a path repository. When a source failed it says so: `Release discovery was DEGRADED — "no update" below may hide a newer release`. It changes nothing and always exits `0`.

### `update:packages:auto`

```bash
bin/semitexa update:packages:auto --json
```

When auto-deploy is off it ends with status `skipped`. With no newer release it ends with `noop`. It also ends with `noop`, without running composer, when `composer.json` pins every package that has a newer release at exactly the version installed; the reason names them. Otherwise it:

1. Takes `var/lock/semitexa-update.lock`, the same lock `bin/semitexa update` uses. If another run holds it, this run fails and the next tick tries again.
2. Starts a run-journal record.
3. Keeps a copy of `composer.json` and `composer.lock` in memory.
4. Runs `composer update 'semitexa/*' --with-all-dependencies --prefer-dist --no-dev --no-interaction --optimize-autoloader`, with `composer` from the `PATH` or `vendor/bin/composer`.
5. Compares every `semitexa/*` package in `composer.lock` with `vendor/composer/installed.json` and fails if one is missing or at another version. A `require-dev` package is not counted as missing: `--no-dev` takes it out of `vendor/` while `composer.lock` keeps it.
6. Runs `orm:sync`, then `cache:clear`. When the server runs in the same container, `cache:clear` also reloads its workers.
7. Restarts: with `SEMITEXA_AUTO_DEPLOY_RESTART_COMMAND`, that command. Otherwise `docker compose restart`, but only when `docker-compose.yml` and the Docker CLI exist and the command is not running inside a container. Otherwise nothing, and the result says `restart_required: true`.
8. Fetches `SEMITEXA_AUTO_DEPLOY_HEALTHCHECK_URL` when it is set; a failure fails the deploy and rolls it back. After a restart in step 7 it tries four times, three seconds apart. Without one (inside the `app` container) it asks the workers `cache:clear` reloaded on the new `vendor/`. It is the only health check that can roll a deploy back: the systemd wrapper's check after its restart only reports.

After step 5, when composer installed none of the newer releases (a constraint, or a pinned package a newer one needs, held them back) and `composer.lock` did not change, the run ends as `noop` there: no sync, no restart, and the reason says so. Composer still runs on each such tick until the constraints move.

The result is `updated`, `noop` or `failed`. The command exits `1` only for `failed`. With `--json` it prints one line of JSON, including `status`, `reason`, `selected_version`, `updated_packages`, `restart_required`, `restart_status`, `healthcheck`, `rollback`, `run_journal`, `release_set` and the source settings.

Through `bin/semitexa` the command runs inside the `app` container, so step 7 never runs `docker compose restart` there and the result says `restart_required: true`; the systemd wrapper below restarts from the host. Leave `SEMITEXA_AUTO_DEPLOY_RESTART_COMMAND` unset on such a project: it would run inside that container too, where there is no Docker CLI, so `bin/semitexa server:start` cannot work as a restart command.

`update:packages:auto` does not edit `composer.json`. Composer moves a package only as far as its constraint allows. A project created from `semitexa/ultimate` pins every `semitexa/*` package to an exact version, so there auto-deploy reports `noop` until the pins move: take a new release set with `bin/semitexa update` ([Upgrading](../get-started/upgrading.md)). Auto-deploy is for a project whose constraints let packages move (`^2026.10`, `*`). `updated_packages` in the result names each package's `deployed_version`, the version `vendor/` holds afterwards.

### The systemd timer

Install the timer as root, passing the project root:

```bash
cd /srv/semitexa/my-project
sudo vendor/semitexa/update/tools/install-auto-deploy-systemd.sh /srv/semitexa/my-project
sudo systemctl enable --now semitexa-auto-deploy.timer
```

The installer needs `bin/semitexa` in that directory and an existing user to run as. It writes `semitexa-auto-deploy.service` and `semitexa-auto-deploy.timer`, copies the wrapper script to `<project>/tools/run-auto-deploy-systemd.sh` (and the install.sh refresh script to `<project>/tools/refresh-install-sh.sh`), and runs `systemctl daemon-reload`. A run that takes longer than 30 minutes is stopped. A project path with a double quote, a backslash or a newline is refused; `%` and `$` in one are escaped for systemd. The copy in `tools/` is not refreshed when the package updates; run the installer again to refresh it.

| Variable | Default | Meaning |
|---|---|---|
| `SEMITEXA_AUTO_DEPLOY_UNIT_PREFIX` | `semitexa-auto-deploy` | the unit names |
| `SEMITEXA_AUTO_DEPLOY_TIMER_INTERVAL` | `15m` | time from the start of one run to the next |
| `SEMITEXA_AUTO_DEPLOY_SYSTEMD_DIR` | `/etc/systemd/system` | where the units are written |
| `SEMITEXA_AUTO_DEPLOY_RUN_AS_USER` | `root` | the service's `User=`; `HOME` is that user's home directory |
| `SEMITEXA_AUTO_DEPLOY_ENABLE` | `0` | `1` enables and starts the timers |

Pass them on the installer's command line, for example `sudo SEMITEXA_AUTO_DEPLOY_TIMER_INTERVAL=1h vendor/semitexa/update/tools/install-auto-deploy-systemd.sh /srv/semitexa/my-project`. Set `SEMITEXA_AUTO_DEPLOY_RUN_AS_USER` to the user that runs `bin/semitexa` for this project.

The timer first fires one minute after it starts (two minutes after boot), then `SEMITEXA_AUTO_DEPLOY_TIMER_INTERVAL` after each run started, with up to one minute of random delay. The service is `Type=oneshot`.

The installer also writes `semitexa-refresh-install-sh.service` and `.timer`. They are not for your project, and `SEMITEXA_AUTO_DEPLOY_ENABLE=1` leaves that timer off unless the project has a `packages/semitexa-ultimate` checkout, and turns off one an earlier install enabled.

Each tick runs the wrapper, which:

1. Runs `./bin/semitexa update:packages:auto --json`. When that fails, it stops with its exit code; first, if the deploy was rolled back and the result says `restart_required: true`, it runs `./bin/semitexa server:restart`, so every container goes back onto the restored release. A rollback that `FAILED` restarts nothing: `vendor/` is half-written then.
2. Reads `status` and `restart_required` from that JSON. It needs no PHP on the host, only `curl` (which the bootstrap installs).
3. When the status is `updated` and `restart_required` is true, runs `./bin/semitexa server:restart`, holding the update lock (`var/lock/semitexa-update.lock`, with `flock`), so a `bin/semitexa update` started meanwhile is refused as "already in progress" rather than having its container stopped mid-composer: it builds the image, then takes every container down and starts them again, so the `worker` container (the queue, scheduler and mail workers) runs the new code too. The app is down until it boots. Then it fetches the health-check URL from the host and wants a 2xx answer, trying six times, five seconds apart, up to about a minute and a half. A failure there exits `1`; it prints the URL without its query string or credentials, and passes it to `curl` on standard input, not on its command line. Nothing is rolled back at this point, and the next tick finds nothing newer to deploy: the site stays on the release that failed until you act.

Check and trigger it:

```bash
systemctl list-timers --all | grep semitexa
sudo systemctl start semitexa-auto-deploy.service
journalctl -u semitexa-auto-deploy.service -n 100 --no-pager
```

## When a deploy fails

Any failure after the lock is taken ends the run as `failed`, with the error as its `reason`. When `composer update` itself failed before changing `composer.lock` or `vendor/` (the repository unreachable, an expired token), there is nothing to roll back: `rollback` says `not needed`, and nothing restarts. Otherwise — a later step failed, or composer failed after rewriting the lock — the run rolls back:

1. It writes the saved `composer.json` and `composer.lock` back. If there was no lock before, the new one is deleted.
2. It runs `composer install --prefer-dist --no-dev --no-interaction --optimize-autoloader`.
3. It restarts the same way step 7 does. Where nothing restarts (inside the `app` container), it runs `cache:clear` instead, so the workers reload onto the restored `vendor/`, and the result says `restart_required: true` for the wrapper, which then restarts the stack.

`rollback` in the result says how far it got:

| Value starts with | Meaning |
|---|---|
| `performed` | files and `vendor/` are back on the previous versions, and the restart ran |
| `partial` | files and `vendor/` are back, but nothing restarted: the restart failed, or there was none to run (inside the `app` container the workers were reloaded with `cache:clear`). The systemd wrapper restarts the stack; without it, restart by hand |
| `FAILED` | the files could not be written, or `composer install` failed: `vendor/` is still on the new versions |

The rollback does not undo schema changes `orm:sync` made and does not repeat the health check. The saved files are kept in memory only: a run killed mid-update has nothing to roll back from.

Each run is recorded in two places:

- **Deployment log.** One JSON file per run in `var/log/deployments/`, named by UTC time (`2026-10-21T091500Z.json`). It holds the same fields as the `--json` output. It is written on every run, `skipped` and `noop` included; the newest 200 are kept and older ones removed (the `remote-bootstrap-*.json` logs in the same directory are not counted). If it cannot be written, the result says `deployment_log: unavailable: …` and the run's outcome stays as it was.
- **Run journal.** A row in `platform_update_run_journal` with kind `auto-deploy`, written only when a deploy is attempted. Its package deltas are what `vendor/` holds after a successful run (a package composer held back is left out) and what was attempted for a failed one. When the database is unreachable the row is skipped. Read it with `bin/semitexa update:history`.

## Exact versions: `update:packages:materialize`

```bash
bin/semitexa update:packages:materialize
bin/semitexa update:packages:materialize --write-root
```

It builds a manifest from `composer.json`:

- every `semitexa/*` package in `require` and `require-dev` gets the exact installed version;
- each path repository under `packages/semitexa-*` is removed when that package has an open license, and becomes an HTTPS `vcs` repository when its license is proprietary or missing. Other repositories are kept;
- `semitexa/dev` moves from `require-dev` to `require`;
- `minimum-stability` becomes `stable` and `prefer-stable` becomes `true`.

By default it writes `composer.release.json` (`--output` changes the path, inside the project). `--write-root` writes it over `composer.json` and keeps the previous file as `composer.dev-workspace.json`. It needs `composer.lock` to exist.

`update:packages:bootstrap-remote` builds a release archive whenever `composer.release.json` exists.

## Is a deploy zero-downtime?

No. Nothing in these commands runs a second copy of the app, switches traffic, or rolls containers one at a time. There is one `app` container, and it publishes `SWOOLE_PORT` itself.

- `composer update` rewrites `vendor/` in place, in the directory the running workers load code from.
- `cache:clear` reloads the workers. A leaving worker gives each request it is answering up to two seconds, and cuts its SSE streams at once ([Worker Memory and Recycling](worker-recycling.md)). A reload does not reload classes the server loaded before it started its workers. The notes on `server:reload` say a reload covers changes inside existing files, and that new, renamed or deleted classes need `server:restart`.
- `docker compose restart` stops and starts every container. Requests are refused while `app` is down.
- `bin/semitexa server:restart` runs `docker compose down` and then `up -d`; `server:restart app` recreates only the `app` container. Either way the app is down until the new container boots.

The systemd wrapper restarts the whole stack after a deploy that installed something, so every class is the new one in the app and in the workers; that restart is the outage. Run by hand, `update:packages:auto` leaves it to you: `bin/semitexa server:restart`.
