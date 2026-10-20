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

In a project started with `bin/semitexa server:start` and `EVENTS_ASYNC=1`, these workers are already running: a `worker` service, in a container of its own so it can be scaled apart from the web server, runs every line `bin/semitexa worker:processes` prints. Each package names what it needs with `#[AsWorkerProcessSource]`: core one `queue:work` per queue its listeners and handlers use, mail `mail:work`, media `media:work`, the scheduler `scheduler:plan` (once a minute) and `scheduler:work`, webhooks `webhook:work`.

## Why this matters

Operability matters as much as functionality. Separate inspect, plan, execute, and replay actions mean the platform can be observed and intervened on deliberately instead of forcing operators to restart processes and hope for the best.
