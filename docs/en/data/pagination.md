---
id: data/pagination
section: data
slug: pagination
title: Pagination
summary: Offset and cursor pagination out of the box — switch modes with a single query parameter.
order: 60
locale: en
status: canonical
keywords:
  - PaginatedResult
  - limit()
  - offset()
  - cursor pagination
  - total count
---
# Pagination

Semitexa ORM repositories support both offset-based and cursor-based pagination through the same query API, switchable at runtime via a request parameter.

## How it works

A handler reads `mode`, `limit`, and `page` from the payload, validates bounds (`page >= 1`, `limit > 0` with an upper cap), computes `offset = (page - 1) * limit`, and calls `findPage($limit, $offset)` on the repository. The repository uses `limit()` and `offset()` on the query builder. Switching to cursor mode changes only the query strategy — the handler and repository interface stay identical. A `countAll()` call provides the total for page count computation, and the validated bounds keep offsets non-negative.

## Why this matters

Offset pagination is simple but degrades on large datasets. Cursor pagination stays performant at scale. Having both available through the same repository contract means the choice can be deferred until the data size makes it matter, without rewriting handler logic.
