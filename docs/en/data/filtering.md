---
id: data/filtering
section: data
slug: filtering
title: Filtering
summary: "#[Filterable] indexes a column; filter with findBy() criteria or a typed where() on the query builder."
order: 50
locale: en
status: canonical
verified_against: 2026.11.24.1203
keywords:
  - "#[Filterable]"
  - findBy()
  - where()
  - Operator
---
# Filtering

`#[Filterable]` on a resource model property adds a single-column, non-unique index on its column. It does not build queries: filtering goes through the repository or the query builder.

## How it works

Pass criteria to the repository: `$repo->findBy(['status' => 'open', 'deletedAt' => null])` matches each property to its value, and a `null` value matches `IS NULL`. For any other condition, use the query builder: `$repo->query()->where(ColumnRef::for(OrderResource::class, 'amount'), Operator::GreaterThan, 100)->fetchAllAs(Order::class)`. A resource that has `use HasColumnReferences;` can write the reference as `OrderResource::column('amount')`. A handler passes the payload's values to one of the two. The property names come from the resource's typed references, never from the request.

Earlier versions of this page described `FilterableTrait` and `FilterableResourceInterface`. Neither is a way to filter. A final readonly resource model cannot use the trait, and no repository reads the criteria it collects.

## Why this matters

An index on the columns a screen filters by keeps those queries fast, and declaring it on the property keeps it next to the column definition. Building conditions from typed references rather than strings means a misspelled property throws when the query is built, instead of matching nothing.
