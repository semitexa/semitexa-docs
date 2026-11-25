---
id: data/n-plus-one
section: data
slug: n-plus-one
title: N+1 Without Magic
summary: Semitexa avoids N+1 with resource slices that load the exact columns and relations each screen needs, instead of hiding queries behind implicit loading.
order: 90
locale: en
status: canonical
verified_against: 2026.11.24.1203
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

A screen defines its own resource model, a separate final readonly class such as `ProductCardResource`. It points at the same table with `#[FromTable]` but declares only the four columns it renders. There is no base class to extend. The schema merges the classes that share a table, so `orm:sync` still sees one table. If the screen also needs related data, `ResourceModelRelationLoader` batch-loads all related rows for the full result set in a second query, never one per row. There are no proxies and no lazy property access.

## Why this matters

Implicit lazy loading makes local code look simple while database traffic scales silently with row count. An explicit resource slice keeps the fetch plan visible in code review, stable in production, and free from the runtime surprises that fat-entity ORMs introduce when a new template starts touching a previously unloaded property.
