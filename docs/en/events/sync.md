---
id: events/sync
section: events
slug: sync
title: Sync Events
summary: Dispatch an event and all sync listeners run before the response is sent.
order: 20
locale: en
status: published
keywords:
  - "#[AsEvent]"
  - "#[Propagated]"
  - "#[AsEventListener]"
  - EventExecution::Sync
  - EventDispatcherInterface
---
# Sync Events

Synchronous listeners execute inline before the response is sent. When you dispatch an event in sync mode, every registered listener completes its work before the HTTP response is returned to the client.

## How it works

The event dispatcher calls each sync listener in registration order within the current request lifecycle. The response is not flushed until all sync listeners have returned.

## Why this matters

Sync execution guarantees that listener side effects are complete before the client sees the response. This is the right choice for validation side effects, required audit writes, or any work the response depends on. When the ledger runtime is enabled, propagated events are also written to the node ledger after sync listener execution.
