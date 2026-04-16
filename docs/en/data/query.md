---
id: data/query
section: data
slug: query
title: Query Builder
summary: Compose type-safe queries with a fluent API — no raw SQL, no magic strings.
order: 40
locale: en
status: canonical
keywords:
  - ResourceModelQuery
  - where()
  - orderBy()
  - limit()
  - fetchAll()
  - fetchOne()
---
# Query Builder

The Semitexa ORM exposes a fluent query API that compiles type-safe constraints against `ResourceModel` column references without raw SQL.

## How it works

A repository opens a query with `->query()` on the ORM repository instance, chains `where()`, `orderBy()`, and `limit()` calls using typed `ResourceModel::column()` references and `Operator` / `Direction` enums, then materializes results with `fetchAllAs()` or `fetchOneAs()`. The result is a concrete typed collection — no magic arrays.

## Why this matters

Raw SQL strings and magic column name literals are the most common source of silent query bugs. Typed column references catch rename mismatches at boot, and the fluent API keeps the query shape visible in code review without losing flexibility for complex filtering or ordering needs.
