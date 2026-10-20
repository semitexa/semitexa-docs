---
id: testing/factories
section: testing
slug: factories
title: Test Data Factories
summary: Build valid resource models for a test in one line with ResourceModelFactory — states, sequences, seeds, and persisted rows with their keys filled.
order: 20
locale: en
status: canonical
verified_against: 2026.10.20.0340
keywords:
  - ResourceModelFactory
  - factory
  - seed
  - test data
---

# Test Data Factories

```php
use Semitexa\Orm\Application\Service\Factory\ResourceModelFactory;

$order  = ResourceModelFactory::for(OrderResourceModel::class)->makeOne();          // unsaved
$orders = ResourceModelFactory::for(OrderResourceModel::class)->count(5)->create($orm); // written, keys filled

ResourceModelFactory::for(OrderResourceModel::class)
    ->state(['status' => OrderStatus::Paid])                       // by property name
    ->sequence(fn (int $i) => ['position' => $i])                  // per row
    ->forTenant('acme')                                            // tenant column + the write's tenant
    ->seed(42)                                                     // same rows every run
    ->count(3)
    ->create($orm);
```

The factory reads the model's column metadata, so it needs no definition per model. Each column's value comes from, in order:

1. `state()` — an array, or a closure that gets the row index; a later `state()` wins over an earlier one;
2. `sequence()`;
3. the column's declared `default`;
4. `null` for a nullable column — unless `filled()`;
5. a generated value that fits the column: its PHP type (an enum case, a `DateTimeImmutable`, a bool) or else its database type, length, precision and scale. An email-named property gets `user<n>@example.test`, and the row number keeps strings apart, so a run of rows does not trip a unique index.

A `uuid` key is left empty and filled by the write; an `auto` key is left to the database and comes back filled; a `manual` key is generated. A soft-delete column stays null and a `#[Version]` column starts at 0 unless a state sets them. A tenant-scoped model needs `forTenant()` before `create()`.

Without `seed()` every call draws fresh values and numbers its rows on from the previous call, so two `createOne()` calls never collide on a unique column. With `seed()` the call gives the same rows every run — uuid foreign keys included. A property no column has is refused. `create($orm)` writes through the ORM's aggregate write engine and returns the models as written. Relations are not created for you: create the parent first and pass its key in `state()`.
