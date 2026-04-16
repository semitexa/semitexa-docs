---
id: data/relations
section: data
slug: relations
title: Relations
summary: Declare parent and child links on the resource itself, then read typed relations from the handler.
order: 70
locale: en
status: canonical
keywords:
  - "#[HasMany]"
  - "#[BelongsTo]"
  - foreignKey
  - typed relations
  - batch loading
---
# Relations

Semitexa resource relations are declared directly on `ResourceModel` properties using `#[HasMany]` and `#[BelongsTo]` attributes. Handlers traverse them through typed repository calls.

## How it works

A `#[HasMany(target: TargetResource::class, foreignKey: 'column_id')]` property on a resource names the child resource and the join column. `#[BelongsTo]` declares the inverse. Handlers read relations by calling repository methods — `findByProduct()`, `findById()`, `findByCategory()` — and the ORM batch-loads the related rows for the full result set in a single query.

## Why this matters

Relation declarations co-located with the resource definition make the data model readable without tracing through handler code. Explicit repository methods for loading relations keep the fetch strategy visible and batch-safe rather than hidden behind property access or lazy proxies.
