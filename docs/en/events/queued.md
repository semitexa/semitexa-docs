---
id: events/queued
section: events
slug: queued
title: Queued Handler
summary: Events survive restarts and scale across workers — backed by a durable message queue.
order: 40
locale: en
status: published
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

## Queue feature support

- Automatic retry on failure
- Dead-letter queue (DLQ)
- Cross-worker delivery
- Priority queues
- Survives worker restart
