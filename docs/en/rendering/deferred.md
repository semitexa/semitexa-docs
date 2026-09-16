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
  - when to defer
  - refreshInterval
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

## When to defer, and when not to

Deferring is not strictly better. A skeleton buys patience for a region that takes time; for a region that does not, it costs a round trip and a frame of placeholder to hide work that had already finished, and it reads as jank.

The rule of thumb:

- **Under ~50 ms of server render** — do not defer. The region is faster than the round trip that would replace it.
- **Over ~200 ms, or unpredictable** — defer. That is a wait worth showing a skeleton for: a slow upstream call, a report, a third-party API.
- **Between the two** — measure the whole page, not the region. Deferring the slowest region of a 90 ms page moves 40 ms off first byte and adds a round trip to get it back.

Measure before deciding, and do not estimate: a request with `?__trace=1` records a `slot.render` span per region rendered inline and a `slot.resolve` span per deferred region, both visible at `/__trace` and through `ai:observe show --id=<process>`. A console measured this way in September 2026 rendered every page in 13–31 ms as a fragment and 24–50 ms as a full document. Not one of its regions was slow enough to deserve a skeleton — and all fifty of its slot resources carried `deferred: true`.

`lint:deferred-slots` reports a slot that declares `deferred: true` while its page renders it inline: the flag reads as a decision and changes nothing until a template calls `layout_slot_deferred()`.

## Deferral and live updates are two decisions

The docs used to present these as one idea. They are two, with opposite thresholds:

- **Deferral** is about FIRST PAINT. It pays off when a region is slow, and costs when it is fast. It ends when the region arrives.
- **Live push** (`refreshInterval`, persistent SSE) is about what happens AFTER first paint — a booking that lands on screen unasked, a counter that moves without a click. Its cost is a held coroutine per connected user, not a round trip.

A fast region can be worth pushing to. A slow region can be worth deferring without ever updating again. Choosing one does not choose the other. See [Live Widgets](deferred-live) for the push side.

## Why this matters

Without deferred delivery, pages with expensive regions either make users wait for the full server render or hand control to a client-side data fetching layer. Deferred slots let the shell render immediately while slow regions catch up, without converting those regions into separate API calls and client-side components.
