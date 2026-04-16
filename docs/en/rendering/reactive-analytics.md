---
id: rendering/reactive-analytics
section: rendering
slug: reactive-analytics
title: Reactive Analytics
summary: Independent analytics jobs can light up one dashboard progressively, while the page stays server-rendered from the first byte.
order: 140
locale: en
status: published
keywords:
  - multi-job snapshots
  - independent panel refresh
  - "refreshInterval: 5"
  - SSR-first live UI
---
# Reactive Analytics

Each panel updates when its own job finishes, so the dashboard feels live without turning into a client-side orchestration layer.

## How it works

Each analytics metric is produced by an independent background job. Each job writes its own snapshot to server storage. A deferred slot with `refreshInterval: 5` re-renders the dashboard HTML every five seconds. Each render reads the latest available snapshot per metric and composes the full dashboard from those server-authoritative values.

The dashboard assembles from server snapshots instead of a frontend sync loop. Panels that have data show values; panels waiting on a job show a pending state. The page model stays consistent throughout.

## Key mechanisms

- **multi-job snapshots** — each panel reads from its own independent job output rather than a single aggregated API call.
- **independent panel refresh** — a new snapshot from one job updates that panel without requiring the other panels to re-fetch.
- **`refreshInterval: 5`** — the slot polls the server every five seconds for updated snapshot data.
- **SSR-first live UI** — the dashboard is server-rendered from first byte to live refresh; no client merge layer required.

## Why this matters

Analytics dashboards with multiple data sources often require a frontend orchestration layer that fetches from multiple endpoints, merges results, and manages per-panel loading states. Composing the dashboard in the slot render function instead means the server owns that merge and the page receives fully composed HTML on each refresh.
