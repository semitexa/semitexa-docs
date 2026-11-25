---
id: data/query
section: data
slug: query
title: Query Builder
summary: Compose queries with a fluent API over typed column references; whereRaw() is the explicit raw-SQL escape hatch.
order: 40
locale: en
status: canonical
verified_against: 2026.11.24.1203
keywords:
  - ResourceModelQuery
  - where()
  - orderBy()
  - limit()
  - orWhere()
  - whereRaw()
  - fetchAllAs()
  - fetchOneAs()
---
# Query Builder

The Semitexa ORM exposes a fluent query API. Conditions name columns through typed references, and raw SQL is an explicit escape hatch.

## How it works

A repository opens a query with `->query()` and chains `where()`, `orWhere()`, `whereIn()`, `orderBy()` and `limit()`. These take column references and the `Operator` and `Direction` enums. A column reference is `ColumnRef::for(OrderResource::class, 'status')`. The shorthand `OrderResource::column('status')` works on a resource that has `use HasColumnReferences;`. `fetchAllAs()` reads the rows as a list of domain objects. `fetchOneAs()` returns one of them, or `null`.

A reference is checked when the query is built: a property the resource does not declare throws `InvalidArgumentException` at that call. It is not checked at boot. `whereRaw($sql, $bindings)` adds a raw condition with bound values, for what the builder cannot express. The tenant scope still applies to it.

## Why this matters

Column names written as strings in SQL are a common source of silent query bugs: a renamed column matches nothing instead of failing. A typed reference fails loudly the first time the query runs, and the fluent chain keeps the query's shape visible in code review. `whereRaw` covers the rest, and it stands out in review because its name says what it is.
