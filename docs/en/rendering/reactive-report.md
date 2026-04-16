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

A background report job runs on a schedule and writes its progress to server storage. A deferred slot with `refreshInterval` set keeps re-requesting its server-rendered HTML. Each render reads the latest job state and returns updated HTML. The page swaps the HTML in place.

The slot starts as SSR output, not as a placeholder for a client-side widget framework. Background jobs update storage, and the slot simply keeps re-rendering the current server truth.

## Key mechanisms

- **`refreshInterval`** — controls how often the slot refreshes from the server.
- **`#[AsScheduledJob]`** — marks the background job that updates progress state.
- **`DemoJobRun`** — stores live job state that the slot turns into HTML.
- **`ReactiveReportSlot`** — owns the live region contract separately from the main page resource.

## Why this matters

The same page can combine static SSR, deferred SSR, and live SSR without changing mental models. Keeping background job progress as server-owned state and reflecting it through slot HTML means the UI stays accurate without a client-side polling layer and without a separate data synchronization mechanism.
