---
id: data/relations
section: data
slug: relations
title: Relations
summary: Declare parent and child links on the resource itself, then load them explicitly, one batched query per relation.
order: 70
locale: en
status: canonical
verified_against: 2026.11.24.1203
keywords:
  - "#[HasMany]"
  - "#[BelongsTo]"
  - foreignKey
  - typed relations
  - batch loading
---
# Relations

Semitexa relations are declared directly on resource model properties with the `#[HasMany]` and `#[BelongsTo]` attributes. Handlers load them explicitly through the repository or the query builder.

## How it works

A `#[HasMany(target: OrderItemResource::class, foreignKey: 'orderId')]` property names the child resource and the child's property that holds the parent's id. `foreignKey` takes a property name, not a column name: `'order_id'` throws, because the child declares no such property. `#[BelongsTo(target: OrderResource::class, foreignKey: 'orderId')]` declares the inverse, and there `foreignKey` is the property on the resource itself.

A relation is loaded only when asked for:

- pass `relations: [RelationRef::for(OrderResource::class, 'items')]` to `findBy()` or `findOneBy()` (on a resource that has `use HasRelationReferences;`, `OrderResource::relation('items')`);
- or call `withRelation()` on a query;
- for a nested relation, give a dot path: `RelationRef::path(OrderResource::class, 'items.product')`.

A `#[HasMany]` or `#[BelongsTo]` relation costs one batched query for the whole result set, never one per row. A `#[ManyToMany]` relation costs two: the pivot, then the targets. A relation that was not asked for stays an unloaded `RelationState`, not a lazy proxy.

## Why this matters

Relation declarations co-located with the resource definition make the data model readable without tracing through handler code. Explicit repository methods for loading relations keep the fetch strategy visible and batch-safe rather than hidden behind property access or lazy proxies.
