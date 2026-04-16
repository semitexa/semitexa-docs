---
id: rendering/reactive-import
section: rendering
slug: reactive-import
title: Reactive Import
summary: Background batches keep moving, and the page reflects server progress as live HTML instead of a client-managed progress app.
order: 130
locale: en
status: published
keywords:
  - "refreshInterval: 2"
  - server-owned progress
  - batch processing
  - SSR-first live UI
---
# Reactive Import

The import keeps running on the server, and the page stays honest by streaming fresh HTML instead of faking progress in frontend state.

## The problem

Long-running import jobs often push teams toward client-side progress simulation. The browser invents a temporary truth while the job is still running somewhere else, and the two states have to be reconciled when the job finishes.

## How it works

An import job runs in batches on the server and writes authoritative progress to storage. A deferred slot with `refreshInterval: 2` re-requests its HTML every two seconds. Each render reads the actual import state and returns the current progress as HTML. The page swaps the HTML in place.

Server state is the only state. Each refresh shows the latest job snapshot directly from the server-owned import pipeline.

## Key mechanisms

- **`refreshInterval: 2`** — the slot checks the server every two seconds for updated progress.
- **server-owned progress** — the import job owns the authoritative progress state; the slot reads it on each render.
- **batch processing** — the import advances in chunks, and each slot refresh picks up the latest committed progress.
- **SSR-first live UI** — the page never switches rendering models; the live region is always server-rendered HTML.

## Why this matters

Client-side progress simulation requires estimating or polling an API and translating the response into local state. Server-owned progress avoids that layer entirely. The slot reflects what the server actually knows, so the progress display is always accurate rather than extrapolated.
