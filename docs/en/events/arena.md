---
id: events/arena
section: events
slug: arena
title: Execution Arena
summary: Launch the same backend intent in sync, Swoole async, and queued modes, then watch the proof arrive over SSE.
order: 10
locale: en
status: published
keywords:
  - EventExecution::Sync
  - EventExecution::Async
  - EventExecution::Queued
  - SSE proof stream
---
# Execution Arena

One browser action emits one backend event. Three listeners with different execution modes turn that same intent into three visibly different response lifecycles.

## How it works

The page opens one SSE session, launches a mode-specific event, and then records proof from both sides: response timing from the launch request and stage-by-stage backend confirmations from the SSE stream.

## Why this matters

This removes hand-wavy "Semitexa supports async" claims. The sync lane visibly blocks, the Swoole lane returns early and completes later, and the queued lane waits for a worker before finishing.

## Execution modes

- **EventExecution::Sync** — runs the listener inline before the HTTP response is finished.
- **EventExecution::Async** — defers the listener with Swoole so the request can finish first.
- **EventExecution::Queued** — serializes the listener work into a transport for a queue worker to consume later.
- **SSE proof stream** — a dedicated EventSource connection that receives backend stage confirmations in real time.
