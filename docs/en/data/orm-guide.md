---
id: data/orm-guide
section: data
slug: orm-guide
title: ORM Working Guide
summary: Declaring a resource, querying it, relations and write policies, optimistic locking, transactions, wiring a store, and the gotchas.
order: 100
locale: en
status: canonical
verified_against: 2026.09.19.1020
keywords:
  - ORM
  - optimistic locking
  - transaction
  - fail-closed tenancy
  - aggregation
---

# ORM Working Guide

The practical manual for the package: how to declare resources, query,
load relations, write aggregates, and wire a store. The structural rules
live in [Module structure](../get-started/module-structure.md); this file is about
using the ORM day to day.

## Concepts in one paragraph

A **resource model** is a `final readonly` class mapped to ONE table via
attributes (`#[FromTable]`, `#[Column]`, `#[PrimaryKey]`). A **domain
model** is your business object; an `#[AsMapper]` class converts between
the two, and `MapperRegistry` routes those conversions. A
`DomainRepository` gives you reads (`findById`, `findBy`, `query()`) and
writes (`insert`/`update`/`delete`) in domain-model terms. The ORM is
deliberately **single-table**: no JOINs — relations load as separate
batched queries, and anything genuinely multi-table is a hand-written
SQL decision, not an accident.

## Declaring a resource

```php
#[FromTable(name: 'orders')]
#[TenantScoped(strategy: 'same_storage', column: 'tenantId')]
#[SoftDelete(column: 'deletedAt')]
final readonly class OrderResource
{
    use HasColumnReferences;
    use HasRelationReferences;

    public function __construct(
        #[PrimaryKey(strategy: 'uuid')]              // string UUID PK; for AUTO_INCREMENT use strategy: 'auto' with `public int $id` + MySqlType::Int
        #[Column(type: MySqlType::Varchar, length: 36)]
        public string $id,

        #[Column(type: MySqlType::Varchar, length: 64)]
        public string $tenantId,

        #[Column(type: MySqlType::Varchar, length: 32)]
        public string $status,

        #[Column(type: MySqlType::Int)]
        public int $amount,

        #[Version]                                    // optimistic locking (see Writes)
        #[Column(type: MySqlType::Int)]
        public int $version = 1,

        #[Column(type: MySqlType::Datetime, nullable: true)]
        public ?DateTimeImmutable $deletedAt = null,

        #[HasMany(target: OrderItemResource::class, foreignKey: 'orderId',
                  writePolicy: RelationWritePolicy::CascadeOwned)]
        public ?RelationState $items = null,
    ) {}
}
```

`bin/semitexa orm:sync` creates/alters the table from this declaration
(FKs, indexes, two-phase destructive ops behind `--allow-destructive`).

## Queries

`$repository->query()` returns a `ResourceModelQuery`. Column and
relation references are TYPED — `ColumnRef::for(Class, 'prop')` /
`RelationRef::for(Class, 'prop')` throw on unknown members, and using a
ref from another class in a query is rejected.

```php
$orders = $repo->query()
    ->where(ColumnRef::for(OrderResource::class, 'status'), Operator::Equals, 'open')
    ->whereIn(ColumnRef::for(OrderResource::class, 'tenantId'), $ids)
    ->orderBy(ColumnRef::for(OrderResource::class, 'amount'), Direction::Desc)
    ->limit(50)
    ->fetchAllAs(OrderDomain::class);           // mapper registry defaults to the repo's
```

Available: `where` (eq/neq/gt/gte/lt/lte/like/notlike), `whereIn`,
`whereNotIn`, `whereNull`/`whereNotNull`, `whereBetween`, `whereAnyLike`
(OR-group), `whereRaw` (placeholder-scanned escape hatch), offset
pagination (`paginate`) and keyset cursors (through the collection feed
compiler).

### Aggregations (single-table)

```php
$total   = $q->sum(ColumnRef::for(OrderResource::class, 'amount'));   // 0 on empty
$mean    = $q->avg(...);                                              // null on empty
$oldest  = $q->min(...);  $newest = $q->max(...);                     // null on empty
$byState = $q->countBy(ColumnRef::for(OrderResource::class, 'status'));
// ['open' => 12, 'done' => 30] — same WHERE/tenant/soft-delete state as fetchAll()
```

### Tenancy is fail-closed

`#[TenantScoped]` has one strategy: the rows of every tenant share the table and are filtered on the tenant column (`column`, the `tenant_id` column by default). `strategy` takes `'same_storage'` (the default) or its other name `'column'`; anything else (`'separate_db'`, `'separate_schema'`, `'connection_switch'`) is refused when the model is read, because a database or schema per tenant is the connection's job (the pool switches on `TenantResolved`), not a model's. Those values used to be accepted and ignored.

A query on a `#[TenantScoped]` resource without tenant context throws a
`LogicException`. Call `forTenant($value)`, or opt out explicitly with
`withoutTenantScope(SystemScopeToken)` — there is no silent global read.

## Relations

Relation properties are typed `?RelationState` and hydrate to an
UNLOADED state; loading is always explicit and always batched (one
`IN (...)` query per relation — no lazy N+1):

```php
$orders = $repo->findBy([...], relations: [
    RelationRef::for(OrderResource::class, 'items'),
    RelationRef::path(OrderResource::class, 'items.product'),   // nested: dot paths
]);
```

Dot paths recurse level by level and stay batched: `items.product` is
exactly two queries regardless of row counts. Every segment is validated
at construction.

### Write policies

Each relation declares who owns the rows on write:

| Policy | Meaning on insert/update/delete |
|---|---|
| `CascadeOwned` | Children are part of the aggregate: written/replaced/deleted with the root |
| `SyncPivotOnly` | Only the pivot table is synced (delete + chunked batch insert); related rows untouched |
| `ReferenceOnly` | Never written; the FK column must already agree with the referenced object (validated) |

**An owned relation must be able to say "not loaded".** `CascadeOwned` and `SyncPivotOnly`
relations are written from the property, so the property's type must admit `RelationState`:

```php
#[ManyToMany(target: TagResource::class, pivotTable: 'article_tags', foreignKey: 'article_id', relatedKey: 'tag_id', writePolicy: RelationWritePolicy::SyncPivotOnly)]
public array|RelationState $tags = [],
```

A model read without the relation then carries it as `RelationState::notLoaded()`, and saving it
leaves the children or pivot rows alone. A plain list (or a loaded `RelationState`) replaces them,
and `[]` clears them. A plain `array $tags = []` is refused when the model's metadata is built:
"not loaded" read as "none", and saving the model deleted every row. A mapper whose domain object
does not carry the relation passes `RelationState::notLoaded()`.

## Writes

`DomainRepository::insert/update/delete` run through the
`AggregateWriteEngine`, and every call is **atomic**: root row +
cascade-owned children + pivot sync commit or roll back together
(`TransactionManager::run`; a caller already inside a transaction nests
as a savepoint). The `ResourceChangedEvent` auto-publish signal fires
strictly AFTER commit.

### Optimistic locking

Declare one int column as `#[Version]`. Updates then guard on the
version the aggregate was read with and bump it in the same statement:

```php
try {
    $order = $repo->update($order);        // WHERE id = ? AND version = ?
} catch (StaleAggregateException) {
    // someone committed first — re-read, reapply, retry
}
```

`update()` returns the aggregate with the BUMPED version — reassign it if
you keep working with the object, otherwise the next update of the stale
in-memory copy will (correctly) throw. Without `#[Version]` updates behave
as before (last write wins).

## Transactions

```php
$orm->getTransactionManager()->run(function (DatabaseAdapterInterface $tx) {
    // statements here MUST use $tx — it is bound to the transaction's
    // connection; the pooled adapter would bypass the BEGIN.
});
```

Nesting creates savepoints. All transaction state is per-coroutine
(`CoroutineLocal`) — safe under Swoole concurrency.

Raw SQL in a store that may run inside someone else's `run()` belongs on
`$orm->getQueryAdapter()`: it joins the transaction open in this coroutine
and is the pooled adapter otherwise. Under Swoole's pool, `$orm->getAdapter()`
takes a connection of its own and autocommits even inside a transaction —
nothing it writes is rolled back, and every coroutine that holds a
transaction and calls it takes a second connection from the pool. In a CLI
process (one shared connection) and on SQLite it joins the transaction
instead, so it is not a way to commit on your own: a lock or lease that
other workers must see at once needs to run outside any transaction. Use
`getAdapter()` for dialect checks (`instanceof MysqlAdapter`):
`getQueryAdapter()` is a decorator.

The engine's `ResourceChangedEvent` auto-publish is commit-gated: a
repository write that nests inside your `run()` buffers its signal on the
TransactionManager and it flushes after YOUR commit (and is dropped on
rollback, including a rolled-back savepoint's). If you call `bufferEvent()`
yourself, wire a dispatcher with `setEventDispatcher()` first — flushing
with no dispatcher discards silently.

The other way round, for something already done outside the database that a
rollback should take back (a file stored for a row about to be written),
`afterRollback(fn () => $storage->delete($path))` runs the work if the
transaction rolls back, or the savepoint it was asked for in, and drops it on
commit; with no transaction open it does nothing. A failure in it is logged and
the rollback's own exception still reaches you. `semitexa/media` uses it for
the original of an upload whose asset row was rolled back.

For an effect a rollback cannot undo — a mail sent, a message published, a
call to another service — hold it back until the commit:

```php
$orm->getTransactionManager()->afterCommit(fn () => $this->notifyWarehouse($orderId));
```

Outside a transaction it runs at once. Inside, it runs once the outermost
transaction commits, after the connection is back in the pool; a rollback
(or the rolled-back savepoint it was asked for in) drops it, so a
`runWithRetry()` replay does not do it twice. A failure after the commit is
logged and does not undo the committed write; outside a transaction the
work's exception reaches you as from any call. It needs no event dispatcher. Inside a
test transaction it never runs. A coroutine started inside the transaction
(`go()`) has no transaction of its own: what it asks for runs at once, even if
the transaction then rolls back.

## Wiring a store

Use the `OrmBackedStore` trait instead of hand-rolling the ritual:

```php
#[AsService]
final class OrderStore
{
    use OrmBackedStore;

    // The injected property MUST live on the class (framework rule:
    // #[InjectAs*] is forbidden inside traits).
    #[InjectAsReadonly]
    protected OrmManager $orm;

    public function open(string $tenantId): array
    {
        return $this->domainRepository(OrderResource::class, OrderDomain::class)
            ->forTenant($tenantId)
            ->query()
            ->where(ColumnRef::for(OrderResource::class, 'status'), Operator::Equals, 'open')
            ->fetchAllAs(OrderDomain::class);
    }
}
```

`findByIdOrFail()` throws the framework `NotFoundException` (HTTP 404).

## Gotchas worth knowing

- **Never memoize what OrmManager hands out across requests** — pools
  self-heal and getters re-check; capture lazily (see the write engine's
  closure-injected dispatcher/transaction manager for the pattern).
- **CLI writes and auto-publish**: the engine resolves its event
  dispatcher per dispatch, so long-running workers keep publishing after
  late bootstrap. Don't "optimize" that closure away.
- `whereRaw` placeholders are scanned quote-aware; still, prefer typed
  refs — raw SQL bypasses column validation, not tenancy (the tenant
  gate applies regardless).
- `orm:sync` never drops without `--allow-destructive`, and drops are
  two-phase (deprecation comment first).
