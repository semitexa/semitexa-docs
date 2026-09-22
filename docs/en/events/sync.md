---
id: events/sync
section: events
slug: sync
title: Sync Events
summary: Dispatch an event and all sync listeners run before the response is sent.
order: 20
locale: en
status: published
verified_against: 2026.09.19.1020
keywords:
  - "#[AsEvent]"
  - "#[Propagated]"
  - "#[AsEventListener]"
  - EventExecution::Sync
  - EventDispatcherInterface
---
# Sync Events

Synchronous listeners execute inline before the response is sent. When you dispatch an event in sync mode, every registered listener completes its work before the HTTP response is returned to the client.

```semitexa-diagram
title: Event execution modes
node: dispatch | Event dispatcher | Receives one event | 0 | 1
node: sync | Sync listener | Runs before response | 1 | 0
node: deferred | Deferred listener | Runs after response | 1 | 1
node: queued | Queued listener | Crosses durable transport | 1 | 2
node: response | HTTP response | Waits for sync only | 2 | 0
node: worker | Queue worker | Handles durable work | 2 | 2
edge: dispatch -> sync | inline
edge: dispatch -> deferred | after response
edge: dispatch -> queued | enqueue
edge: sync -> response | complete first
edge: queued -> worker | consume
```

## How it works

The event dispatcher calls each sync listener in registration order within the current request lifecycle. The response is not flushed until all sync listeners have returned.

## Why this matters

Sync execution guarantees that listener side effects are complete before the client sees the response. This is the right choice for validation side effects, required audit writes, or any work the response depends on. When the ledger runtime is enabled, propagated events are also written to the node ledger after sync listener execution.
