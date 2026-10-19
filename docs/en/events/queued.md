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

A queued listener that throws does not lose its message. The failure follows the listener's retry policy: with `maxRetries` above zero the message goes back on its queue that many times, and once the retries are used up it moves to the failed queue; with the default of `0` it moves there on the first failure:

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

With no retries left the message moves to `<queue>.failed` on the same transport, carrying the listener, the event and the error. The same happens when the message cannot run at all: a listener class that no longer exists goes there at once, and a listener or event that cannot be built follows the retry policy first. A payload that is not a readable message is kept there as it came: `raw` holds it as text, and `raw_base64` holds its exact bytes, which matters when the payload is not valid UTF-8. Nothing replays the failed queue for you: read it with a consumer of your own and publish what should run again back onto the queue. If writing to the failed queue itself fails, the worker logs it and the message is not kept; for an unreadable payload the log line gives its size and SHA-1. A queued payload handler (`#[AsPayloadHandler]`) follows the same policy.

## What reaches the listener

The event is serialized through its **getters** and rebuilt through its **setters**, on the worker that consumes it. A value without a getter does not reach the listener: give every field of a queued event a `getX()` and a `setX()`. The worker creates the event with `new` and no arguments, so a queued event must not have a constructor with required arguments — such an event cannot be rebuilt and ends in the failed queue.

## Queue feature support

- Retry on failure, then a dead-letter queue (`maxRetries`, `retryDelay`)
- Cross-worker delivery
- Survives worker restart (the transport keeps the message; a worker must be running `bin/semitexa queue:work`)
