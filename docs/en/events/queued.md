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

A queued listener that throws is retried, then dead-lettered — nothing is acknowledged and dropped:

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

With no retries left the message moves to `<queue>.failed` on the same transport, carrying the listener, the event and the error, so it can be inspected and replayed. A queued payload handler (`#[AsPayloadHandler]`) follows the same policy.

## What reaches the listener

The event is serialized through its **getters** and rebuilt through its **setters**, on the worker that consumes it. A value without a getter does not reach the listener: give every field of a queued event a `getX()` and a `setX()`.

## Queue feature support

- Retry on failure, then a dead-letter queue (`maxRetries`, `retryDelay`)
- Cross-worker delivery
- Survives worker restart (the transport keeps the message; a worker must be running `bin/semitexa queue:work`)
