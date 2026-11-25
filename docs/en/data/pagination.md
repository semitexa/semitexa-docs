---
id: data/pagination
section: data
slug: pagination
title: Pagination
summary: paginate() returns one page with its total; repositories page by offset, platform collections also by cursor.
order: 60
locale: en
status: canonical
verified_against: 2026.11.24.1203
keywords:
  - PaginatedResult
  - limit()
  - offset()
  - paginate()
  - total count
---
# Pagination

Semitexa ORM repositories paginate by offset. `paginate()` returns one page together with the counts a pager needs.

## How it works

On a `DomainRepository`, call `$repo->paginate($page, $perPage, $criteria)`. At the end of a `query()` chain, call `->paginate($page, $perPage)`. Either returns a `PaginatedResult` with `items`, `total`, `page`, `perPage` and `lastPage`, plus `hasNextPage()`, `hasPreviousPage()` and `isEmpty()`. On a query, `paginateAs($page, $perPage, Order::class)` maps the rows to a domain class.

A page below 1 or a page size below 1 throws `InvalidArgumentException`. The handler validates both from the payload and caps the page size before passing them on. For a hand-built page, `limit()` and `offset()` are still available.

Keyset (cursor) paging is done only by the collection feed compiler, for platform collections, which turns a cursor into a keyset predicate. The repository and the query builder page by offset.

## Why this matters

Offset paging is simple and fits most screens, and because the result carries the total, a pager needs no second call. On very large tables a deep offset gets slow. There, a collection's cursor avoids it, and a hand-built query can page with a `where()` on the last key it saw.
