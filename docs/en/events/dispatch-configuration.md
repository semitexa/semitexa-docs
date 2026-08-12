---
id: events/dispatch-configuration
section: events
slug: dispatch-configuration
title: Domain Event Dispatch and Configuration
summary: Choosing sync or async per handler, the HandlerCompleted event, running the async worker, and how the three event kinds compare.
order: 80
locale: en
status: canonical
keywords:
  - HandlerCompleted
  - async worker
  - sync
  - dispatch
---

# Domain Event Dispatch and Configuration

## Domain Events (business side-effects)

Domain events are for side-effects triggered after business operations. They support sync, async (Swoole defer), and queued (NATS) execution.

Domain listeners use `#[AsEventListener(event: EventClass::class, execution: ...)]` and live in `Event/DomainListener/`.

### Configuration

- **EVENTS_ASYNC** (`.env`): `0` (default) = in-memory (sync); `1` (or `true`/`yes`) = use NATS for async.
- **EVENTS_TRANSPORT**: Override transport: `in-memory` or `nats`.
- **EVENTS_QUEUE_DEFAULT**: Default queue name pattern when not set per handler.

When **EVENTS_ASYNC=1**, the app uses NATS JetStream. If you run with Docker, `bin/semitexa server:start` automatically uses `docker-compose.nats.yml` (so NATS is started and the app connects to the `nats` service via `NATS_PRIMARY_URL`).

### Sync vs async per handler

Every declared handler (e.g. `#[AsPayloadHandler(...)]`) has an **execution** option:

- **Sync** (default): handler runs in the same process and blocks the HTTP response until it finishes.
- **Async**: handler is enqueued; the HTTP response is sent immediately and the handler runs later.

```php
#[AsPayloadHandler(payload: SomeRequest::class, resource: SomeResponse::class, execution: HandlerExecution::Async)]
```

### HandlerCompleted event

`HandlerCompleted` is a **domain event** dispatched once after the entire HandleRequest phase completes. It is not a pipeline phase — it is a side-effect for domain listeners (SSE push, analytics, etc.).

### Running the worker (async events)

```bash
bin/semitexa queue:work
```

## Comparison

| | Pipeline Events | Server Lifecycle Hooks | Domain Events |
|---|---|---|
| Registry | `PipelineListenerRegistry` | `ServerLifecycleRegistry` | `EventListenerRegistry` |
| Attribute | `#[AsPipelineListener]` | `#[AsServerLifecycleListener]` | `#[AsEventListener]` |
| Execution | Always sync (in request) | Always sync (bootstrap path) | Sync / Async / Queued |
| Dispatcher / Invoker | `PipelineExecutor` | `ServerLifecycleInvoker` | `EventDispatcher` |
| Purpose | Request lifecycle phases | Swoole server lifecycle events | Business logic side-effects |
| Location | `Event/System/` | `src/Server/Lifecycle/` or module `Application/Server/` | `Event/DomainListener/` |
