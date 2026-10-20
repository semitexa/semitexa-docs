---
id: events/deferred
section: events
slug: deferred
title: Deferred Handler
summary: Heavy work runs after the response is sent, so the user gets instant feedback.
order: 30
locale: en
status: published
verified_against: 2026.09.19.1020
keywords:
  - EventExecution::Async
  - "Swoole\\Event::defer()"
  - post-response
  - non-blocking
---
# Deferred Handler

Async listeners run after the response via Swoole defer in the same worker. The client receives the HTTP response immediately while the listener completes its work in the background.

## How it works

The container schedules the listener using Swoole's defer mechanism. The HTTP response is flushed first, then the listener runs in the same worker process. This avoids delaying the current response, but heavy deferred work can still reduce throughput on that worker.

Deferred listeners run in the order they were scheduled, with the context of the request that scheduled them: its tenant, signed-in user, locale and theme, although the request has cleared them by then. A worker that is being recycled lets one finish within the same grace window it gives a request still being answered (about two seconds), and cancels it after that.

In a live region (a held-open connection), each re-run runs the route's sync handlers to render a fresh frame, and its async handlers run again after it. The route's queued handlers and the `HandlerCompleted` event ran with the request that opened the connection, and they do not run again on each re-run. Work the opening request deferred runs once the connection has sent what it sends first and starts waiting for updates, not when it closes, and so does work deferred later outside a re-run. Work a re-run defers, such as an async listener of an event a data resolver dispatched, runs after that re-run's frame is written. The work of successive re-runs runs one after another, in order.

## Why this matters

Long-running side effects — email, cache invalidation, audit logging — do not need to delay the response. Marking a listener `EventExecution::Async` gives users instant feedback while still guaranteeing the work runs. The tradeoff is that deferred work does not survive a worker restart (past that grace window, or a crash), which is why durable side effects belong in the queued tier instead.

## Execution mode comparison

| Mode | When it runs | Survives restart | Best for |
|------|-------------|-----------------|----------|
| Sync | Before response | N/A | Validation, required side-effects |
| Async | After response | No | Email, cache bust, audit log |
| Queued | Worker picks up | Yes | Heavy jobs, retry logic, cross-worker |
