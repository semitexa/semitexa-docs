---
id: events/dispatch-configuration
section: events
slug: dispatch-configuration
title: Domain Event Dispatch and Configuration
summary: Choosing sync or async per handler, the HandlerCompleted event, running the async worker, and how the three event kinds compare.
order: 80
locale: en
status: canonical
verified_against: 2026.09.19.1020
keywords:
  - HandlerCompleted
  - async worker
  - sync
  - dispatch
---

# Domain Event Dispatch and Configuration

## Domain Events (business side-effects)

Domain events are for side-effects triggered after business operations. They support sync, async (Swoole defer), and queued (NATS) execution.

Domain listeners use `#[AsEventListener(event: EventClass::class, execution: ...)]` and live in the module's `Application/Handler/DomainListener/`.

### Configuration

- **EVENTS_ASYNC** (`.env`): `0` (default) = in-memory (sync); `1` (or `true`/`yes`) = use NATS for async.
- **EVENTS_TRANSPORT**: Override transport: `in-memory` or `nats`.
- **EVENTS_QUEUE_DEFAULT**: Default queue name pattern when not set per handler.

When **EVENTS_ASYNC=1**, the app uses NATS JetStream. If you run with Docker, `bin/semitexa server:start` automatically uses `docker-compose.nats.yml` (so NATS is started and the app connects to the `nats` service via `NATS_PRIMARY_URL`).

### Sync, async or queued per handler

Every declared handler (e.g. `#[AsPayloadHandler(...)]`) has an **execution** option. It takes the same three values as a listener's, with the same meaning:

- **Sync** (default): the handler runs in the request and the HTTP response waits for it.
- **Async**: the handler runs in the same process right after the response is sent, with the request's context: session, auth, tenant and locale are the request's. The resource it returns is sent to the page over SSE. It runs again on each re-run of a live region, so the region gets a fresh result. The session is saved with the response, so do not count on what the handler writes to it. It is not durable: a worker that is being recycled lets it finish within about two seconds and then cancels it, and there are no retries. A failure is logged.
- **Queued**: the handler is enqueued, and a worker runs it later, in its own container. `transport`, `queue`, `maxRetries` and `retryDelay` apply, and so does the dead-letter queue. The request's context does not: the worker gets copies of the payload and resource, so the handler must not depend on the session or the signed-in user. Use it for heavy work, such as an export, a report or a call to a slow API, so it does not slow the site down. With `EVENTS_ASYNC=0` no worker reads the in-memory transport, so the handler runs in the same process right after the response is sent, without the request's context, as a worker would run it.

```php
#[AsPayloadHandler(payload: SomeRequest::class, resource: SomeResponse::class, execution: HandlerExecution::Async)]
#[AsPayloadHandler(payload: ExportRequest::class, resource: ExportResponse::class, execution: HandlerExecution::Queued, queue: 'exports', maxRetries: 3)]
```

Both get copies of the payload and resource, so neither can change the response that was already sent. An `Async` handler that names a `transport`, `queue` or retries is reported at boot: those apply only to `Queued`. Before the October 2026 releases, `Async` meant what `Queued` means now, so a handler that relied on a worker must now say `Queued`. Messages already waiting for such a handler in its own queue get no worker until it does; `bin/semitexa system:doctor` names them (`queue.stranded-async`: how many wait on which queue, for which handler) on a transport that can count them. A handler that runs `Sync` (no `execution` given) but names a `transport` or `queue` is reported too, since it runs in the request and never uses the queue. So is an `execution` word that is not `sync`, `async` or `queued` (a typo such as `queud`): it runs `Sync`. Each counts as an `invalid_usage` in the boot summary, which `-v` or `BOOT_VERBOSE=1` prints in full (a typo and a queue on one handler are two); the handler still registers. A listener's `execution` is checked strictly already: an unknown word is reported and the listener is not registered. A `Sync` or `Async` listener that names a `transport`, `queue` or retries is reported as well, and still registers: they apply only to `Queued`.

### HandlerCompleted event

`HandlerCompleted` is a **domain event** dispatched once after the entire HandleRequest phase completes. It is not a pipeline phase — it is a side-effect for domain listeners (SSE push, analytics, etc.). A re-run of a live region does not dispatch it, and does not run the route's queued handlers either: a re-run renders a fresh frame, and its async handlers run again for it.

### Running the worker (async events)

`bin/semitexa server:start` runs the worker for you when `EVENTS_ASYNC=1`: one `queue:work` per transport and queue, among the processes `worker:processes` lists. To run one by hand, for example outside Docker:

```bash
bin/semitexa worker:processes      # what the worker runs: "<pause> <command>" per line
bin/semitexa queue:work nats events # one of them
```

Each queue needs one kind of worker. If a listener names the queue `mail:work` reads (`MAIL_QUEUE`), `queue:work` would take the mail and `mail:work` the listener's messages, and each would fail what it took. So `worker:processes` runs neither, and prints on stderr which queue they share. Give each its own queue.

## Comparison

| | Pipeline Events | Server Lifecycle Hooks | Domain Events |
|---|---|---|
| Registry | `PipelineListenerRegistry` | `ServerLifecycleRegistry` | `EventListenerRegistry` |
| Attribute | `#[AsPipelineListener]` | `#[AsServerLifecycleListener]` | `#[AsEventListener]` |
| Execution | Always sync (in request) | Always sync (bootstrap path) | Sync / Async / Queued |
| Dispatcher / Invoker | `PipelineExecutor` | `ServerLifecycleInvoker` | `EventDispatcher` |
| Purpose | Request lifecycle phases | Swoole server lifecycle events | Business logic side-effects |
| Location (in a module) | `Application/Service/` | `Application/Service/` (e.g. `Server/Lifecycle/`) | `Application/Handler/DomainListener/` |
