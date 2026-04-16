---
id: data/n-plus-one
section: data
slug: n-plus-one
title: N+1 Without Magic
summary: Semitexa avoids N+1 by using resource slices for the exact columns and relations each screen needs, instead of hiding database traffic behind implicit relation loading.
order: 90
locale: en
status: canonical
keywords:
  - ResourceModelRelationLoader
  - resource slice
  - no lazy loading
  - "#[FromTable]"
  - batch relations
---
# N+1 Without Magic

Instead of a single fat entity with lazy-loaded relations, Semitexa uses screen-specific resource slices that declare exactly the columns they need. Relations are batch-loaded explicitly.

## How it works

A screen defines its own `ResourceModel` subclass — for example `ProductCardResource` — pointing at the same table via `#[FromTable]` but declaring only the four columns it renders. If the screen also needs related data, `ResourceModelRelationLoader` batch-loads all related rows for the full result set in a second query, never one per row. There are no proxies and no lazy property access.

## Why this matters

Implicit lazy loading makes local code look simple while database traffic scales silently with row count. An explicit resource slice keeps the fetch plan visible in code review, stable in production, and free from the runtime surprises that fat-entity ORMs introduce when a new template starts touching a previously unloaded property.
