---
id: rendering/reactive-report
section: rendering
slug: reactive-report
title: Reactive Report
summary: Background work updates an SSR-first slot in place, so the UI feels live without falling back to SPA state orchestration.
order: 120
locale: en
status: published
keywords:
  - refreshInterval
  - "#[AsScheduledJob]"
  - DemoJobRun
  - SSR-first live UI
---
# Reactive Report

A scheduled job changes server state, and the slot keeps reflecting that state live with no page reload and no client-side state machine.

## The problem

Background jobs often force teams to invent a parallel frontend state machine just to show progress. Even simple status pages get split into initial SSR and later client-managed rendering logic. The result feels live, but the architecture quietly drifts into a small SPA around one widget.

## How it works

A background report job runs on a schedule and writes its progress to server storage. A deferred slot with `refreshInterval` set keeps its SSE connection open, and the SERVER re-renders it on that cadence and pushes the HTML down. Each render reads the latest job state and the page swaps the HTML in place. There is no request per refresh; the only requests after the first are the framework's own SSE reconnects.

The slot starts as SSR output, not as a placeholder for a client-side widget framework. Background jobs update storage, and the slot simply keeps re-rendering the current server truth.

### Who may connect, and for how long

The slot rides the canonical `/__semitexa_kiss` stream, so the page inherits that stream's access rules rather than any of its own. By default an anonymous connection is refused with `401 Unauthorized`: a status page meant for signed-out visitors will simply never start. `SSE_PUBLIC_ANONYMOUS=true` opens it to them.

Opening it does not remove the limits. The configured connection caps still apply, and `SSE_MAX_CONNECTION_AGE_SECONDS` still ends a connection that has been held too long — the client reconnects, which is an ordinary HTTP request and counts like one. A report page left open on a wallboard is a held coroutine per viewer for as long as the cap allows, so the capacity question is how many viewers, not how many refreshes.

## Key mechanisms

- **`refreshInterval`** — how often the server re-renders and pushes. Its cost is a held coroutine per connected user, which makes this a capacity decision as well as a UX one.
- **`#[AsScheduledJob]`** — marks the background job that updates progress state.
- **`DemoJobRun`** — stores live job state that the slot turns into HTML.
- **`ReactiveReportSlot`** — owns the live region contract separately from the main page resource.

## Why this matters

The same page can combine static SSR, deferred SSR, and live SSR without changing mental models. Keeping background job progress as server-owned state and reflecting it through slot HTML means the UI stays accurate without a client-side polling layer and without a separate data synchronization mechanism.
