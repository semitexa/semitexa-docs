---
id: data/filtering
section: data
slug: filtering
title: Filtering
summary: Mark a property #[Filterable] and the ORM handles the rest — no manual WHERE clauses.
order: 50
locale: en
status: canonical
keywords:
  - "#[Filterable]"
  - FilterableTrait
  - FilterableResourceInterface
  - getFilterCriteria()
---
# Filtering

The `#[Filterable]` attribute on a `ResourceModel` property registers that column as a valid filter target. The ORM builds the WHERE clause automatically from the declared filter criteria.

## How it works

A resource implements `FilterableResourceInterface` and uses `FilterableTrait`. Marking a property `#[Filterable]` makes that column available to `getFilterCriteria()`. Repositories pass the criteria to the query builder, which compiles the WHERE clause without manual string construction. Handlers simply pass payload values through to the repository.

## Why this matters

Manual WHERE clause construction per property is repetitive and error-prone. A single attribute declaration keeps filter capability co-located with the column definition, and the ORM ensures that only declared filterable columns can appear in generated queries.
