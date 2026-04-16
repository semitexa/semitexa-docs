---
id: rendering/deferred
section: rendering
slug: deferred
title: Deferred Blocks
summary: SSR renders the shell first, then expensive regions stream in as real HTML over SSE — no SPA handoff and no client-side page rebuild.
order: 90
locale: en
status: published
keywords:
  - "#[AsSlotResource(deferred: true)]"
  - skeletonTemplate
  - SSE push
  - SSR-first live UI
---
# Deferred Blocks

The page is usable immediately, and slow regions arrive later as server-rendered HTML instead of hydration-heavy client code. SSR renders the shell first, then expensive regions stream in as real HTML over SSE — no SPA handoff and no client-side page rebuild.

## How it works

`#[AsSlotResource(deferred: true)]` marks a region for late delivery. The page renders and sends the shell immediately. The server then processes each deferred slot and streams the final HTML into the correct position over SSE. The browser swaps in HTML instead of rebuilding the page from client state.

A `skeletonTemplate` can be specified so the region shows a meaningful placeholder while the final HTML is in transit.

## Key mechanisms

- **`#[AsSlotResource(deferred: true)]`** — marks a region for late delivery over SSE.
- **`skeletonTemplate`** — optional placeholder rendered in the shell while the slot loads.
- **SSE push** — the server streams rendered slot HTML fragments over a persistent HTTP connection.
- **SSR-first live UI** — the page model stays server-driven from first byte to final slot render.

## Why this matters

Without deferred delivery, pages with expensive regions either make users wait for the full server render or hand control to a client-side data fetching layer. Deferred slots let the shell render immediately while slow regions catch up, without converting those regions into separate API calls and client-side components.
