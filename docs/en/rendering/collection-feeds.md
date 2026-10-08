---
id: rendering/collection-feeds
section: rendering
slug: collection-feeds
title: Collection Feeds
summary: A live, paginated, searchable, sortable, filterable feed for platform.grid in one class — #[AsCollectionFeed] over an ORM model and a list of fields; no payload setters, no field map, no projection, no response class.
order: 137
locale: en
status: canonical
verified_against: 2026.10.03.1952
keywords:
  - "#[AsCollectionFeed]"
  - CollectionFeed
  - platform.grid
  - semitexa/crud
  - DeclaresWatchScopesInterface
---

# Collection Feeds

A feed is the data route behind a `platform.grid`. With `semitexa/crud` it is one class:

```php
use Semitexa\Crud\Application\Payload\Request\CollectionFeed;
use Semitexa\Crud\Attribute\AsCollectionFeed;
use Semitexa\PlatformUi\Domain\Model\Field\Field;

#[AsCollectionFeed(
    path: '/admin/pings/feed',
    name: 'admin.pings.feed',
    model: PingResource::class,          // the ORM resource model
    paginationMode: 'auto',              // page | cursor | auto
    defaultPerPage: 5,
    perPageOptions: [5, 10, 25],
    countThreshold: 10,
)]
final class PingsFeed extends CollectionFeed
{
    public function fields(): array
    {
        return [
            Field::id()->filterable(),
            Field::text('label')->searchable()->sortable()->filterable(),
            Field::datetime('createdAt')->label('Created')->sortable(),
        ];
    }
}
```

```twig
{{ component('platform.grid', {gridId: 'pings', endpoint: '/admin/pings/feed'}) }}
```

The UI Playground's pings grid is this class. It replaced a 249-line payload, a 154-line
handler, a JSON response class and a Resource DTO.

## What follows from the fields

| From | You get |
| --- | --- |
| `searchable()` fields | `?q=` searches them (`LIKE`, pushed down to SQL) |
| `sortable()` fields | `?sort=field` / `-field` |
| `filterable()` fields | `?filter=field:op:value`, with the operators of the field's [type](field-types.md) (`eq`, `in`, `contains`, and the range ends `gte` / `lte`) |
| a filterable number, date or moment | a range: the grid shows "from" and "to" inputs (`?filter=price:gte:10;price:lte:20`). A moment's "to" a day includes that whole day (UTC), and an end that is not a number or a real day is a typed 400 |
| a filterable `choice` | its options served as `meta.filterOptions`, so the grid shows a select |
| the field list | the contract's `ui` block: columns in order, their formats and badge tones, the filters, the row `idField` |
| the model | the query, the tenant scope, and the **live watch**: its `#[ResourceKey]` (default: its table) |

Every ORM write to the model already publishes an invalidation on its resource key. So the grid
refreshes live, through the one KISS stream, with no publish code.

A field reads the model property of the same name, or its snake_case twin (`createdAt` reads
`created_at`). Set `->set('property', 'other_name')` to read something else.

The row is JSON-plain: a moment is written `Y-m-d H:i:s` in UTC, an enum as its value. A value
that cannot be a cell (an object, an array) fails with a message rather than rendering
`[object Object]`.

## Narrowing and projecting

```php
public function query(ResourceModelQuery $query): ResourceModelQuery
{
    return $query->where(PingResource::column('label'), Operator::NotEquals, ''); // only labelled pings
}

public function row(object $model, UiFieldSet $fields): array
{
    return parent::row($model, $fields) + ['region' => explode(' · ', $model->label)[1] ?? ''];
}
```

## Tenancy

A model marked `#[TenantScoped]` is read for the request's tenant only. The handler adds the scope
itself, and the ORM refuses an unscoped read of such a model anyway. A `#[TenantExempt]` model is
read whole.

## The contract

`OPTIONS` on the feed path returns the `collection` block. It is built by the same builder as a
route declaring `#[CollectionPaginated]` / `#[CollectionSortable]` / … on its response class, so a
field-driven feed and an attribute-declared route describe themselves identically. Next to it is
the `ui` block, which the grid renders instead of guessing columns from field names.

## Rows that are not one model: a source

A feed whose rows come from somewhere else (a repository, an external API, an in-memory store)
names a `source` instead of a `model`:

```php
#[AsCollectionFeed(path: '/ui-playground/admin/leads/feed', name: 'ui-playground.leads.feed',
    source: LeadsSource::class, watch: ['ui_playground_leads'], paginationMode: 'auto')]
final class LeadsFeed extends CollectionFeed
{
    public function fields(): array { /* … */ }
}

#[AsService]
final class LeadsSource implements CollectionSourceInterface
{
    public function slice(CollectionCriteria $criteria, CollectionFeed $feed): CollectionSlice
    {
        // $criteria is already checked against the fields: q, sort, filter terms, page or cursor.
        return new CollectionSlice($rows, page: CollectionPage::compute(request: $criteria->page, total: $total, mode: 'page'));
    }
}
```

- **What stays the same:** the contract, the request checking and the live transport.
  `watch:` names the scopes it refreshes on, since there is no model to watch.
- **What the source owns:** tenancy and access, because there is no model for the feed to scope.
  It answers in its own terms and returns rows keyed by field name, with a numbered or a cursor
  page.

The UI Playground's leads grid is this: `LeadsFeed` and `LeadsSource` over the lead submission
repository. They replaced a payload, a handler, a JSON response and a Resource DTO (575 lines).

## Limits

- **No typed `output` block.** The rows are projected from fields, not from a `#[ResourceObject]`.
  A feed that must also be a typed public API (OpenAPI, GraphQL) still declares a Resource DTO and
  a response class with `#[ProducesResourceCollection]`.
- **One model per feed, or a source.** A report joining several models narrows one model with
  `query()`, or serves its rows from a source.

## Under the hood

- A handler bound to the abstract `CollectionFeed` serves every subclass. The core allows a
  handler to name an abstract payload with no route yet.
- The live watch comes from the route attribute: `AsCollectionFeed` implements the core's
  `DeclaresWatchScopesInterface`, which `WatchScopesOf` reads for both the subscription and the contract.
- `CollectionFeedSupport::criteriaFrom()` checks the request against declarations built from the
  fields (`CollectionDeclarations`).
