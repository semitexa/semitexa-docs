---
id: rendering/deferred-live
section: rendering
slug: deferred-live
title: Live Widgets
summary: A live slot can refresh itself on a timer while the page stays SSR-first — no SPA runtime and no handwritten polling layer.
order: 110
locale: en
status: published
keywords:
  - refreshInterval
  - auto-refresh
  - SSE reconnection
  - SSR-first live UI
---
# Live Widgets

Set `refreshInterval` and the server keeps re-rendering the widget for you. Live UI without converting the page into an app shell.

## The problem

Live UI often pushes teams toward a separate client-side state system for even simple status widgets. Polling code and reconnection logic usually leak into ad hoc JavaScript instead of staying part of the rendering model. The shell and the live widget can drift into two different architectures even though they belong to one page.

## How it works

A deferred slot with `refreshInterval` set will re-request its server-rendered HTML on a timer. The server renders the slot fresh each time, and the page swaps the new HTML into position. SSE connection recovery is handled by the framework, so reconnection logic does not need to be written by hand.

## Key mechanisms

- **`refreshInterval`** — declares the refresh cadence in seconds directly on the slot resource.
- **auto-refresh** — the framework handles the timed request and HTML replacement cycle.
- **SSE reconnection** — the framework recovers dropped connections automatically without custom retry code.
- **SSR-first live UI** — the slot stays part of the server-rendered page model rather than becoming a client-managed widget.

## Why this matters

Without a framework-level refresh mechanism, live regions require bespoke polling loops, reconnection handlers, and custom HTML replacement code. Declaring `refreshInterval` on the slot keeps the live behavior co-located with the slot contract and removes the need for per-widget polling infrastructure.
