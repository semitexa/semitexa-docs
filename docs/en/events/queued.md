---
id: events/queued
section: events
slug: queued
title: Queued Handler
summary: Events survive restarts and scale across workers, backed by a durable message queue.
order: 40
locale: en
status: published
verified_against: 2026.09.19.1020
keywords:
  - EventExecution::Queued
  - queue transport
  - NATS
  - retry
  - DLQ
---
# Queued Handler

Queued listeners are serialized into a durable transport and processed by separate workers. The event payload leaves worker memory and enters the queue, so the work survives restarts and can scale horizontally.

## How it works

When an event is dispatched with `EventExecution::Queued`, the dispatcher serializes the event and pushes it to the configured transport (NATS by default). A queue worker picks it up independently of the originating request or worker process.

## Why this matters

Async deferred execution runs in the same worker and is lost on restart. Queued execution survives crashes, scales across worker pools, and supports retry and dead-letter queue (DLQ) semantics. Use queued listeners for any work that must not be lost: payment processing, third-party webhook calls, import jobs, and similar durable side effects.

## When a listener fails

Behind a queue (`EVENTS_ASYNC=1`), a queued listener that throws does not lose its message. The failure follows the listener's retry policy: with `maxRetries` above zero the message goes back on its queue that many times, and once the retries are used up it moves to the failed queue; with the default of `0` it moves there on the first failure:

```php
#[AsEventListener(
    event: OrderPaid::class,
    execution: EventExecution::Queued,
    maxRetries: 3,   // attempts after the first; default 0
    retryDelay: 5,   // seconds between them; default 0
)]
final class ChargeCardListener
{
    public function handle(OrderPaid $event): void { /* ... */ }
}
```

On the database transport a message published inside a database transaction joins it: a rollback removes it, and no worker sees it before the commit (one published from a coroutine started inside the transaction does not join it). A message whose run throws comes back after 5 s, then 10, 20 and 40 (with the default five attempts), and then becomes a dead letter. Only the worker that still holds its lease acknowledges or requeues it: one that ran past its 300 s lease leaves a message another worker took over alone. A message whose worker died on its last attempt becomes a dead letter once its lease runs out; if that worker was only slow and finishes after all, the dead letter is removed. The database and NATS transports hold a retry back for `retryDelay` themselves, so the worker goes on with the next job meanwhile, and a retry that was waiting survives a worker restart. On NATS a retry pulled before it is due is handed back for the time that remains, which counts as one delivery; on its last allowed delivery it runs at once instead. A transport that cannot hold a message back (the in-memory one, a custom one, or the dual-write one over a primary that cannot) makes the worker wait before it requeues, as it always did.

With no retries left the message moves to `<queue>.failed` on the same transport, carrying the listener, the event and the error. The same happens when the message cannot run at all: a listener class that no longer exists goes there at once, and a listener or event that cannot be built follows the retry policy first. A payload that is not a readable message is kept there as it came: `raw` holds it as text, and `raw_base64` holds its exact bytes, which matters when the payload is not valid UTF-8. `bin/semitexa queue:failed` lists what is there and `queue:retry` queues it again — see [Watching the queues](#watching-the-queues). If writing to the failed queue itself fails (NATS unreachable, or a message the added fields push past the server's payload limit), the worker logs it and leaves the message on its queue, to be delivered again; on NATS, after its fifth delivery it is dropped, and the log says so. An unreadable payload is not kept: the log line gives its size and SHA-1. A queued payload handler (`#[AsPayloadHandler]`) follows the same policy.

## What reaches the listener

The event is serialized through its **getters** and rebuilt through its **setters**, on the worker that consumes it. A value without a getter does not reach the listener: give every field of a queued event a `getX()` and a `setX()`. The worker creates the event with `new` and no arguments, so a queued event must not have a constructor with required arguments — such an event cannot be rebuilt and ends in the failed queue.

## Queue feature support

- Retry on failure, then a dead-letter queue (`maxRetries`, `retryDelay`)
- Cross-worker delivery
- Survives worker restart (the transport keeps the message until a worker consumes it)

## Running the worker

`bin/semitexa server:start` starts a `worker` service in a container of its own (`docker-compose.worker.yml`), and `server:stop` stops it. Being its own container, it can be given its own resources and scaled apart from the web server: set `WORKER_REPLICAS=3` in `.env` and run `bin/semitexa server:restart`. Start it through `bin/semitexa`, not a bare `docker compose`: `server:start` hands the worker the database, cache and broker addresses the overlays give the app. It runs every process `bin/semitexa worker:processes` lists: one `queue:work` for the default queue and for every queue a queued listener or a queued handler names in `queue:`, and what the other installed packages need (`mail:work`, `media:work`, `scheduler:plan` once a minute and one `scheduler:work` per pool, `webhook:work`), each started again when it exits. A queue or package you add later is picked up by `bin/semitexa server:restart`. With `EVENTS_ASYNC=0` nothing is queued, so the worker runs only what needs no queue (the scheduler and webhook delivery): the transport would be in-memory, one per process, which no worker can read. A queued listener then runs in the process that dispatched the event, as an `Async` one does (after the response, in a request). It gets the event rebuilt from its getters, and it is resolved and run with no request context (no tenant, signed-in user or locale of the request), as a worker would, so the rules in "What reaches the listener" still apply. A failure is logged rather than thrown at the caller, and `maxRetries`, `retryDelay` and the failed queue do not apply. A mail sent with `MailSendMode::Queued` (the default) is sent at once, within the request, in a single attempt.

**Stopping a worker.** `server:stop`, `server:restart` and a deploy stop the worker container with `docker stop`, which sends SIGTERM. `queue:work`, `mail:work` and `media:work` catch it: the job in hand runs to its end and is acknowledged, and the command exits 0 (it prints `Signal 15: stopping after the message in hand (again to stop at once).`). `scheduler:work` and `webhook:work` do the same with the run or delivery in hand. The next job stays queued for the next worker; on NATS, messages the worker had already pulled with the job in hand go straight back to the stream. A worker that is waiting for messages stops within a second or two. Processes a job started itself (a converter, a renderer) are not signalled and finish with it. A second signal (Ctrl-C twice, in a terminal) stops the worker at once. A job that takes longer than `WORKER_STOP_GRACE_PERIOD` (`90s` by default; set it in `.env` with a unit, such as `5m`) is killed, and runs again from the start once its lease or acknowledgement wait runs out, as after any crash. Catching the signal needs PHP's `pcntl` extension, which the scaffold image includes; without it a signal ends the worker at once, as it did before. What happens to a scheduler run that is cut off anyway is in [Scheduler](../runtime/scheduler.md).

## Watching the queues

```bash
bin/semitexa queue:stats                      # every queue a worker reads, last 15 minutes
bin/semitexa queue:stats --queue=orders --window=2h --json
bin/semitexa queue:failed --queue=orders      # dead letters, newest first; --payload prints them
bin/semitexa queue:retry <id> --queue=orders  # queue it again, attempts start over
bin/semitexa queue:retry --all --queue=orders --forget
```

`queue:stats` shows per queue: jobs done, retried and dead-lettered, the rate per minute, how long jobs waited (from entering the queue to starting) and ran — average and p95 — and how many wait now and for how long. Percentiles are estimates from fixed buckets (10 ms … 30 s): "p95 ≤ 250 ms" means 95 % finished within 250 ms.

- **Where the counters live** (`QUEUE_STATS_STORE`): `auto` (default) uses Redis when `REDIS_HOST` is set, so a worker in its own container and the command in another read the same numbers; otherwise files under `var/queue-stats`, shared only on one host. `file` and `off` choose explicitly. Each finished job is written at once; counters are kept a week.
- **Dead letters** are what the transport already keeps on `<queue>.failed`: database rows (also messages the transport itself gave up on, a message whose worker died on its last attempt included, once its lease runs out), or messages on the NATS stream. A retry queues a fresh copy and removes the letter; on NATS, whose queue stream allows no delete, the letter is marked handled instead, and a second retry of the same letter inside JetStream's duplicate window is dropped. A payload that was never readable can only be forgotten.
- **How long the database keeps dead letters:** a queue's dead letters (its `<queue>.failed` copies and its own failed rows) older than `QUEUE_DEAD_LETTER_RETENTION_DAYS` (14 by default; `0` keeps them forever) are removed by that queue's worker once an hour, up to 10,000 at a time, dated by their last try. `queue:retry` works on one until then. The queue's own waiting and running messages are never removed, whatever their age; everything on `<queue>.failed` counts as a dead letter.
- **How long NATS keeps messages:** the queue stream keeps every message — done, waiting or dead-lettered — for `NATS_QUEUE_MAX_AGE_DAYS` whole days (14 by default; `0` keeps them forever; anything else, such as a typo, keeps the 14), then drops it without a trace: a dead letter can be retried with `queue:retry` for that long, and a job still waiting when it gets that old — its worker stopped for two weeks — is gone, uncounted. A stream made before this setting existed is given the max age the next time a worker starts or a job is published or retried, and JetStream deletes everything older at once: on an upgrade, set `NATS_QUEUE_MAX_AGE_DAYS` first to keep older dead letters. Only the max age changes; the rest of the stream's configuration, edits made with the `nats` tool included, is kept.
- **The in-memory transport** keeps nothing to inspect: the commands say so.
- **Mail and media** have workers of their own (`mail:work`, `media:work`), and their queues are listed with the others. They count into the same counters: a mail sent, retried or failed for good (`failed`, or `enqueue_failed` when its retry could not be queued), a media variant made or failed (a failed variant is not retried, so it counts as dead-lettered). A message they skip (a mail already sent, a variant already made or gone) counts nothing, and they record no wait: their messages carry whole seconds. A mail or variant that fails is kept on its own row — a mail with status `failed` or `enqueue_failed` (see [Mail](../runtime/mail.md)), `media:failed-variants` for media; only a message the worker itself crashed on until the transport gave up becomes a dead letter on the queue, listed and retried like any other.
- **In the OS:** the Queues app (`/os/app/queues`, opened by asking for "queues" or "failed jobs") shows the same table for the last 15 minutes and the five newest dead letters of each queue, errors cut to 200 characters and payloads never shown. It is read-only: retry and forget with `queue:retry`. It is for the operator only: the queues hold every tenant's jobs, so an Owner sees it and anyone else, a tenant's admin included, gets 403 (with OS sign-in switched off, as on a personal desktop, it is open). A queue whose transport cannot be read is a row saying so, and its dead letters are listed as "Not read" rather than as none. Counters the store cannot give are shown as `?`, not 0, and leave the live depth and the dead letters readable. `queue:stats` says the same (`error` and `counters_error` in `--json`) and exits non-zero in either form.

