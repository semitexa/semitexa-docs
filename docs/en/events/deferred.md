---
id: events/deferred
section: events
slug: deferred
title: Deferred Handler
summary: Heavy work runs after the response is sent — the user gets instant feedback.
order: 30
locale: en
status: published
keywords:
  - EventExecution::Async
  - "Swoole\\Event::defer()"
  - post-response
  - non-blocking
---
# Deferred Handler

Async listeners run after the response via Swoole defer in the same worker. The client receives the HTTP response immediately while the listener completes its work in the background.

## How it works

The container schedules the listener using Swoole's defer mechanism. The HTTP response is flushed first, then the listener runs in the same worker process without blocking any subsequent request handling.

## Why this matters

Long-running side effects — email, cache invalidation, audit logging — do not need to delay the response. Marking a listener `EventExecution::Async` gives users instant feedback while still guaranteeing the work runs. The tradeoff is that deferred work does not survive a worker restart, which is why durable side effects belong in the queued tier instead.

## Execution mode comparison

| Mode | When it runs | Survives restart | Best for |
|------|-------------|-----------------|----------|
| Sync | Before response | N/A | Validation, required side-effects |
| Async | After response | No | Email, cache bust, audit log |
| Queued | Worker picks up | Yes | Heavy jobs, retry logic, cross-worker |
