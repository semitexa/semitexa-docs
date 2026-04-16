---
id: events/ledger
section: events
slug: ledger
title: Ledger Demo
summary: Dispatch a protected demo event and inspect only the persisted demo ledger rows through a safe read-only view.
order: 60
locale: en
status: published
keywords:
  - "#[Propagated]"
  - "#[RequiresPermission]"
  - typed session nonce
  - SQLite read-only view
---
# Ledger Demo

Protected demo routes can append propagated events into Semitexa Ledger and inspect the result through a filtered read-only view. This page is not public: authorization is required, the write action is fixed, and the inspection surface exposes only filtered demo events.

## How it works

The route is guarded with `#[RequiresPermission('products.read')]`, so guests and unauthorized sessions never reach the ledger page. The POST surface accepts only `action=fire` plus a session-bound nonce. No arbitrary SQL, replay, event class, or payload input is exposed here. The inspector opens the SQLite ledger in read-only mode and filters the view to the demo domain only.

## Why this matters

The ledger demo shows that propagated events are append-only, tamper-evident, and inspectable without granting raw database access. The read-only filtered view is the correct pattern for any surface that needs to surface ledger state to users: it scopes the query to a known domain, opens the file in read-only mode, and never exposes unrelated system events.
