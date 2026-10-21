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
  - RollsBackDatabase
  - RefreshesTables
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

## Leave the database clean

`RollsBackDatabase` (semitexa/testing) runs each test inside a transaction that is rolled back after it. The rows the test writes — and the rows the code it calls writes, through a repository, `new OrmManager()` or a route called with `CallsRoutes` — are gone when the next test starts:

```php
use Semitexa\Testing\Traits\RollsBackDatabase;

final class PlaceOrderTest extends TestCase
{
    use RollsBackDatabase;
    use CallsRoutes;

    public function test_an_order_is_stored(): void
    {
        ResourceModelFactory::for(CustomerResourceModel::class)->createOne(new OrmManager());
        $this->postJson('/api/orders', ['sku' => 'A-1'])->assertStatus(201);
    }
}
```

The transaction opens before `setUp()` and is rolled back after `tearDown()`. Every ORM manager in the test process joins it, and a `TransactionManager::run()` inside the test becomes a savepoint, so nothing commits. That has two consequences:

- Resource-changed signals never fire, for any write in the test, with a transaction of its own or without: inside the test transaction every write waits for a commit that never comes. Listeners on them (a site map refresh, cache invalidation) are not exercised; assert on rows, or test those listeners with `RefreshesTables`.
- It refuses an in-memory SQLite database (one per connection: the test's would be empty). Database work inside a coroutine is not supported while it is open: one connection cannot be shared between coroutines, so a coroutine asking a pool for a connection is refused rather than given one that commits outside the transaction.
- On MySQL, DDL (`CREATE`, `ALTER`, `DROP`) and `TRUNCATE` commit the transaction on their own. The test then fails after it ends, naming the cause, instead of leaving rows behind. Create tables in `setUpBeforeClass()`.

The default connection is rolled back; override `connectionsToRollBack()` to add named ones. The trait refuses to start inside a Swoole coroutine. Hooks left on by an earlier `Co\run()` do not matter: outside a coroutine every pool hands out the test's connection.

A test that cannot run inside a transaction — the code under test commits on purpose, runs coroutines, or changes the schema — empties its tables instead, with `RefreshesTables`:

```php
use Semitexa\Testing\Traits\RefreshesTables;

final class ImportCatalogueTest extends TestCase
{
    use RefreshesTables;

    protected function tablesToRefresh(): array
    {
        return [ProductResourceModel::class, 'import_log'];   // a resource's table, or a table name
    }
}
```

The tables are emptied before every test; `refreshTables(...)` empties them on demand. On MySQL they are truncated with foreign key checks off, so `AUTO_INCREMENT` starts again; inside a `RollsBackDatabase` test they are emptied with `DELETE`, which the rollback undoes. On SQLite rows are deleted and `AUTOINCREMENT` counters reset. Both traits work on the database the test run is configured with: `bin/semitexa test:run` uses the test database (`DB_TEST_DATABASE`), never the one you develop against.
