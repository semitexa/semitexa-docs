---
id: cli/workers-scheduling
section: cli
slug: workers-scheduling
title: Workers & Scheduling
summary: Run queues, scheduler pools, mail delivery, webhooks, and tenant-scoped commands from a coherent operator surface instead of bespoke daemons.
order: 40
locale: en
status: canonical
verified_against: 2026.09.19.1020
keywords:
  - queue:work
  - worker:processes
  - worker:run
  - scheduler:list
  - scheduler:plan
  - scheduler:work
  - webhook:work
  - tenant:run
---
# Workers & Scheduling

Semitexa is not only request-response code. The CLI also owns the long-running workers and operator interventions that keep the platform moving.

## How it works

`queue:work`, `webhook:work`, and `mail:work` are separate explicit processes rather than hidden side-effects of the web runtime. The scheduler surface is split into `scheduler:list` (inspect), `scheduler:plan` (materialize), and `scheduler:work` (run the loop) so teams can inspect state before pushing harder. `tenant:run` executes any command inside a concrete tenant context, including queue and cache operations.

In a project started with `bin/semitexa server:start`, these workers are already running: a `worker` service, in a container of its own so it can be scaled apart from the web server, runs every line `bin/semitexa worker:processes` prints. Each package names what it needs with `#[AsWorkerProcessSource]`: core one `queue:work` per queue its listeners and handlers use, mail `mail:work`, media `media:work`, the scheduler `scheduler:plan` (once a minute) and one `scheduler:work` for each pool its jobs name (`scheduler:work` reads one pool; a pool chosen only when a job is dispatched is named by the package that dispatches it, as workflow does for its timeout pools), webhooks `webhook:work`. It runs whatever `EVENTS_ASYNC` says: with `EVENTS_ASYNC=0` and no `EVENTS_TRANSPORT` the queue consumers name nothing (the transport is in-memory, one per process), and the scheduler and webhooks still run; `EVENTS_TRANSPORT=nats` or `database` gives them their `queue:work` all the same. `WORKER_REPLICAS` in `.env` sets how many worker containers run.

The container runs one command, `bin/semitexa worker:run`. It loads the framework once and starts each line of `worker:processes` as a fork of itself, so the roles share what was loaded instead of loading it once each: a role adds 3 to 6 MB of its own (five roles took about 74 MB each as separate `php` processes). Each role is still a process of its own, named `semitexa <command>` in `docker compose top worker`:

- one that exits is started again after its pause (5 s; `scheduler:plan` every 60 s), and one that failed or was killed is named in the worker's log first (`worker: mail:work nats mail exited (1); again in 5s`); the other roles are not touched;
- `kill <pid>` inside the container restarts one role: it finishes the job in hand, exits, and is started again;
- `docker stop` reaches every role, which finishes the work in hand; Docker waits `WORKER_STOP_GRACE_PERIOD` for it (`90s` by default, the worker service's `stop_grace_period`) and then kills what is still running (see [Queued Handler](../events/queued.md#running-the-worker));
- a role started again runs the code that was loaded when the worker started; when a file it was started with has changed since, the worker's log names the files and says how to load them;
- `bin/semitexa server:reload` and `bin/semitexa server:restart app` send the worker a HUP: every role finishes the job in hand, and the worker starts again with the code as it is now, so a deploy or a `composer update` needs one of them. `bin/semitexa server:restart` restarts the whole stack, as before.

The project's worker service decides this, in `docker-compose.worker.yml`: the scaffold's runs `worker:run` when the core has it and otherwise starts a `php` for each line, as before. A project that keeps an older worker service keeps one process per line whatever its core, until `bin/semitexa update` brings the new one.

## Why this matters

Operability matters as much as functionality. Separate inspect, plan, execute, and replay actions mean the platform can be observed and intervened on deliberately instead of forcing operators to restart processes and hope for the best.
