---
id: data/search
section: data
slug: search
title: Search
summary: Declare a search index over a table, run free-text and filtered searches through SearchManagerInterface, and know what SQL LIKE matching, page-local ranking, tenant scoping and the planner hook really do.
order: 130
locale: en
status: canonical
verified_against: 2026.10.20.0340
keywords:
  - SearchManagerInterface
  - SearchIndexDefinition
  - SearchFieldDefinition
  - searchText()
  - SearchRequestFactory
  - SearchTextParser
  - OrmRankingStrategy
  - SearchScope
  - SEARCH_PLANNER_ENABLED
  - search.query-planner
---

# Search

`semitexa/search` turns "the user typed some words and ticked a few filters" into one query: free text matched with SQL `LIKE` across the fields you declare, typed filters, sorting, pagination, a tenant predicate, and a relevance score per hit. It is installed in every project.

Use it when people type text and expect ranked matches across several columns. For a lookup by one known column, a repository query is shorter to write and cheaper to run. It is not a full-text engine: there is no tokenising, stemming or inverted index (see [Performance](#performance)).

**Before you start:** as shipped, the ORM backend has no query factory wired in, so every `search()` call fails with `SearchBackendException: Backend does not support index '…' (backend: 'orm')`. A project has to supply the backend itself; [Providing the backend](#providing-the-backend) shows how.

## Declaring an index

An index is a `SearchIndexDefinition`: a name, a document type, and the fields.

```php
use Semitexa\Search\Domain\Enum\SearchFieldType;
use Semitexa\Search\Domain\Enum\SearchMatchStrategy;
use Semitexa\Search\Domain\Enum\SearchScope;
use Semitexa\Search\Domain\Model\SearchFieldDefinition;
use Semitexa\Search\Domain\Model\SearchIndexDefinition;

final class ProductSearchIndex
{
    public const NAME = 'products';

    public static function definition(): SearchIndexDefinition
    {
        return new SearchIndexDefinition(
            name: self::NAME,
            documentType: 'product',
            fields: [
                new SearchFieldDefinition('title', SearchFieldType::Keyword, searchable: true, sortable: true, weight: 2.0),
                new SearchFieldDefinition('sku', SearchFieldType::Keyword, searchable: true, matchStrategy: SearchMatchStrategy::Prefix),
                new SearchFieldDefinition('status', SearchFieldType::Enum, filterable: true),
                new SearchFieldDefinition('price', SearchFieldType::Number, filterable: true, sortable: true),
                new SearchFieldDefinition('created', SearchFieldType::Date, filterable: true, sortable: true, column: 'created_at'),
            ],
            scopeMode: SearchScope::Tenant,   // the default
            defaultSort: ['created_at'],
        );
    }
}
```

`SearchIndexDefinition` constructor:

| Argument | Default | Meaning |
|---|---|---|
| `name` | — | the index name requests refer to; must not be empty |
| `documentType` | — | a label copied into every hit's `type`; must not be empty |
| `fields` | — | at least one `SearchFieldDefinition`; duplicate names throw |
| `scopeMode` | `SearchScope::Tenant` | see [Tenants](#tenants) |
| `backend` | `'orm'` | the backend name; the shipped backend only accepts `'orm'` |
| `repositoryClass`, `entityClass` | `null` | stored, but nothing in the package reads them |
| `defaultSort` | `[]` | **column** names, always ascending, used only when the request has no sort |
| `plannerEnabled` | `false` | allows the planner for this index (see [The planner](#the-planner)) |

`SearchFieldDefinition` constructor: `name`, `type`, then `searchable`, `filterable`, `sortable` (all `false`), `matchStrategy` (`Contains`), `weight` (`1.0`, negative throws) and `column` (`null`, meaning the field name).

What each flag does:

- **`searchable`** — the free-text query is matched against this field with `LIKE`, and it counts towards the relevance score.
- **`filterable`** — the field may appear in `filters` and in `field:value` text syntax. Any other field in `filters` is rejected.
- **`sortable`** — the field may appear in a `SortClause`. A sort on any other field is silently skipped.
- **`matchStrategy`** — the `LIKE` pattern built from the query: `Exact` → `term`, `Prefix` → `term%`, `Contains` → `%term%`. `%`, `_` and `\` typed by the user are escaped, so they match literally.
- **`weight`** — multiplies this field's relevance score. It never changes which rows the SQL returns.
- **`column`** — the column name used in SQL, **and** the key read from each returned row. Both must agree (see [Watch out for](#watch-out-for)).

`SearchFieldType` (`Exact`, `Prefix`, `Contains`, `Keyword`, `Enum`, `Date`, `Number`) does much less than its names suggest. It does **not** choose the match: only `matchStrategy` does. The type decides three things: range filters (`from`/`to`) are accepted only on `Date` and `Number`; a scalar filter on a `Number` must be numeric; and the type is listed in the planner prompt.

### Registering it

Indexes are not discovered. Register them in `SearchIndexRegistryInterface`, which is one instance per worker, shared with `SearchManager`. Registering the same name twice throws `InvalidArgumentException`, so guard with `has()`:

```php
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Search\Domain\Contract\SearchIndexRegistryInterface;
use Semitexa\Search\Domain\Contract\SearchManagerInterface;
use Semitexa\Search\Domain\Model\SearchResult;

#[AsService]
final class ProductSearch
{
    #[InjectAsReadonly]
    protected SearchIndexRegistryInterface $indexes;

    #[InjectAsReadonly]
    protected SearchManagerInterface $search;

    public function find(string $text): SearchResult
    {
        if (!$this->indexes->has(ProductSearchIndex::NAME)) {
            $this->indexes->register(ProductSearchIndex::definition());
        }

        return $this->search->searchText(ProductSearchIndex::NAME, $text);
    }
}
```

Searching an index that was never registered throws `SearchIndexNotFoundException`.

## Running a search

`SearchManagerInterface` has two methods:

```php
search(SearchRequest $request): SearchResult
searchText(string $index, string $rawQuery, int $limit = 20, array $filters = []): SearchResult
```

### searchText(): one string from a search box

`searchText()` parses the raw input with `SearchTextParser`, merges the result with `$filters`, builds the request through `SearchRequestFactory` (so the configured limits apply) and calls `search()`.

```php
$result = $this->search->searchText('products', 'desk lamp status:active,draft');
// query: "desk lamp", filters: ['status' => ['active', 'draft']]
```

The syntax `SearchTextParser` accepts:

- The input is trimmed and split on whitespace. Empty input means no query and no filters.
- A token `field:value` becomes a filter when `field` is a declared **filterable** field and `value` is not empty. Field names are case-sensitive.
- A comma makes a list: `status:active,draft` → `['active', 'draft']` (items trimmed).
- Every other token, including `unknown:value`, `Status:x` and `https://…`, stays in the text query.
- Repeating a field keeps the last one: `status:a status:b` → `'b'`.
- There are no quotes, ranges, negation or boolean operators. A value cannot contain a space.
- The remaining tokens are joined with single spaces and matched **as one string**: `desk lamp` matches `…desk lamp…`, not a row with `desk` in one place and `lamp` in another.
- On the same key, `$filters` passed to `searchText()` win over filters parsed from the text.

`searchText()` takes no sort, offset or scope. For those, build the request yourself.

### search(): a full request

`SearchRequest` is an immutable value: `index`, `query` (`null` or non-empty), `filters`, `sort` (`list<SortClause>`), `limit` (≥ 1, default 20), `offset` (≥ 0), `scope`, `tenantId`. Build it with `SearchRequestFactory` to have `SEARCH_MAX_QUERY_LENGTH`, `SEARCH_MAX_FILTER_COUNT` and `SEARCH_MAX_RESULT_LIMIT` enforced:

```php
use Semitexa\Search\Application\Service\SearchRequestFactory;
use Semitexa\Search\Domain\Model\SortClause;
use Semitexa\Search\Exception\SearchValidationException;

try {
    $request = (new SearchRequestFactory())->create(
        index: 'products',
        query: 'lamp',
        filters: [
            'status' => ['active', 'draft'],            // list → IN (...)
            'price'  => ['from' => 10, 'to' => 50],     // range → BETWEEN
            'created' => ['from' => '2026-01-01'],      // open range → >=
        ],
        sort: [new SortClause('price', 'DESC')],
        offset: 40,                                     // limit: null → SEARCH_DEFAULT_LIMIT
    );
    $result = $this->search->search($request);
} catch (SearchValidationException $e) {
    $problems = $e->getViolations();                    // list<string>
}
```

Filter values are a scalar (`=`), a non-empty list of scalars (`IN`), or a range with `from`, `to` or both. Unknown fields, non-filterable fields, ranges on a field that is not `Date` or `Number`, empty lists and non-scalar items throw `SearchValidationException` from `search()`. `SortClause` accepts `ASC`/`DESC` in any case and throws on anything else.

A `SearchRequest` built with `new` skips the factory's limits entirely; only the value object's own checks (limit ≥ 1, offset ≥ 0, non-empty query) apply.

### What the backend receives

For the request above, the shipped translator (`OrmSearchTranslator`) calls the query object in this order:

```
where('tenant_id', '=', <tenant>)          -- tenant indexes, see Tenants
whereIn('status', ['active', 'draft'])
whereBetween('price', 10, 50)
where('created_at', '>=', '2026-01-01')
whereAnyLike(['title' => '%lamp%', 'sku' => 'lamp%'])   -- one group, only when there is a query
orderBy('price', 'DESC')                   -- or defaultSort, ascending, when the request has no sort
limit(20)->offset(40)
```

then `fetchAll()` for the page and `count()` for the total. Both are called on the same query after `limit()` and `offset()`: an implementation's `count()` must ignore them, or `total` is the page size.

## Results

`SearchResult`:

| Member | Meaning |
|---|---|
| `hits` | `list<SearchHit>` for this page |
| `total` | the backend's `count()` |
| `partial` | `true` when this page holds fewer rows than `total`, i.e. there are other pages. It does not signal an error |
| `metadata` | `backend`, `index`, `latency_ms`, `query_used`, `filter_count`, `ranking_mode` (`relevance` with a query, `default` without) |
| `isEmpty()` | `total === 0` |
| `count()` | number of hits on this page |

`SearchHit`: `documentId` (the row's `id`, else `uuid`, else a per-process object id), `index`, `type` (the index `documentType`), `score`, `fields` (declared fields keyed by field name, for every column present in the row) and `snippet` (always `null` from the ORM backend).

Each row is turned into an array with its `toArray()` method when it has one, otherwise with an `(array)` cast.

### Ranking

`OrmRankingStrategy` scores each hit in PHP, after the rows are fetched. For every searchable field it compares the lower-cased value with the lower-cased query:

| Value vs query | Points |
|---|---|
| equal | 3 |
| starts with the query | 2 |
| contains the query | 1 |

Points are multiplied by the field's `weight` and summed. With a query, hits are then sorted by score descending, ties by `documentId`; without a query every score is `0` and the SQL order stands.

Ranking happens **after `LIMIT`/`OFFSET`**. It orders the 20 rows of this page, not the whole match set: the best match may sit on page 3 while page 1 shows weaker ones in score order. And when a query is present it replaces the requested sort within the page: `sort: price DESC` decides *which* rows land on the page, the score decides their order.

## Tenants

`SearchScope` has two cases, `Tenant` and `Global`. The index's `scopeMode` decides what happens to the request:

- **Tenant index** (the default). The request's scope is forced to `Tenant`. If the request carries no `tenantId`, the current tenant from `semitexa/tenancy` is used, and `default` when there is no tenant context. The backend then adds `where('tenant_id', '=', $tenantId)`. The column is always `tenant_id`; it cannot be renamed.
- **Global index.** The request is left as it is. A request with scope `Tenant` (the default) and a `tenantId` gets the same `tenant_id` predicate; any other gets none.

The translator checks this again: a request for a tenant index that reaches it with scope `Global` or without a `tenantId` (a backend called directly, not through `SearchManager`) throws `LogicException` instead of searching every tenant.

A `tenantId` set on the request is used as given: nothing checks that it matches the current tenant. Never fill it from user input.

## The planner

The package has a hook for rewriting a query before it runs ("cheap red lamps" → query `lamp`, filters `colour: red`, sort by price), and the pieces of an LLM planner. The hook is not connected to an LLM.

When the hook runs: `SEARCH_PLANNER_ENABLED=1`, **and** the index has `plannerEnabled: true`, **and** the request has a query. All three; otherwise it is skipped. Both switches are off by default, and setting either back to off turns it off.

What runs: `SearchManager` always uses `NoOpSearchQueryPlanner`, which returns the request unchanged. It has no injection point, so there is currently no way to plug in another `SearchQueryPlannerInterface` through the manager, and no class in the package implements one that calls a model.

The building blocks, for a planner you write and call yourself:

- `SearchQueryPlannerInterface::plan(SearchIndexDefinition, SearchRequest, SearchPlannerPolicy): SearchPlanningResult`.
- `LlmPlannerBridgeInterface::execute(string $prompt, int $timeoutMs): string` — the call to a provider, returning raw JSON. Nothing implements it; `semitexa/llm` is only a suggested dependency.
- `LlmPlannerPromptBuilder::build($definition, $userQuery, $policy)` renders the catalog prompt `search.query-planner` (channel `search`) with the index name, document type, field list and allowed operators. Inspect it with `bin/semitexa prompt:show --id=search.query-planner`.
- `LlmPlannerSchemaValidator::validate($parsed, $definition, $policy)` returns violations for unknown or non-filterable filter fields, unknown or non-sortable sort fields, bad directions, and confidence outside 0–1. It does not check operators.
- `SearchPlannerPolicy`: `minConfidence` (0.5), `timeoutMs` (3000), `allowedOperators` (`=`, `in`, `between`, `like`). The manager fills the first two from `SEARCH_PLANNER_MIN_CONFIDENCE` and `SEARCH_PLANNER_TIMEOUT_MS`; it does not enforce the timeout itself.

How the manager applies a plan, should one ever be wired: a planner that throws, reports confidence below the minimum, or returns any warning leaves the request unchanged. A usable plan replaces the query (a `null` query from the planner keeps the old one), merges its filters over the request's (after the request's own filters were validated, so the planner's are not), and replaces the sort when it returns one. The trace is attached to the `SearchRequest` and does not appear in the `SearchResult`.

## Providing the backend

`SearchManager` sends every request to the one bound `SearchBackendInterface`:

```php
supports(SearchIndexDefinition $definition): bool
search(SearchIndexDefinition $definition, SearchRequest $request): SearchResult
```

`OrmSearchQueryInterface`, which your query object implements, has `where`, `orWhere`, `whereAnyLike` (one parenthesised group of `column LIKE pattern` joined by `OR`, ANDed with the rest — the text match), `whereIn`, `whereBetween`, `orderBy`, `limit`, `offset`, `fetchAll` and `count`.

The shipped `OrmSearchBackend` needs an `OrmSearchQueryFactoryInterface`, but its property carries no injection attribute and the package has no implementation of that interface, so it always reports `supports() === false`. Bind your own backend with `#[SatisfiesServiceContract(of: SearchBackendInterface::class)]`. `OrmSearchTranslator` and `OrmRankingStrategy` are plain classes with no constructor; your backend can reuse them by implementing `OrmSearchQueryInterface` (`where`, `orWhere`, `whereIn`, `whereBetween`, `orderBy`, `limit`, `offset`, `fetchAll`, `count`) over your repository's query.

Two implementations now exist for one contract. Run `bin/semitexa contracts:list` and check that yours is the active one; [Resolving Service Contracts](../di/contract-resolution.md) explains how the winner is chosen and how to pick it with a generated resolver.

When you write `whereAnyLike()`: it is one parenthesised group of `column LIKE pattern` conditions joined by `OR`, ANDed with everything before it, so the tenant predicate and the filters still hold for text matches. `ResourceModelQuery::whereAnyLike()` builds that group for a single pattern; with one pattern per column, build the group yourself.

## Configuration

| Variable | Default | Effect |
|---|---|---|
| `SEARCH_DEFAULT_LIMIT` | `20` | page size when `SearchRequestFactory::create()` gets `limit: null`; must be 1…`SEARCH_MAX_RESULT_LIMIT` |
| `SEARCH_MAX_RESULT_LIMIT` | `100` | factory rejects a larger limit |
| `SEARCH_MAX_QUERY_LENGTH` | `500` | factory rejects a longer query (characters); must be ≥ 1 |
| `SEARCH_MAX_FILTER_COUNT` | `20` | factory rejects more filter fields; must be ≥ 1 |
| `SEARCH_PLANNER_ENABLED` | `0` | `1` lets the planner hook run (see above) |
| `SEARCH_PLANNER_TIMEOUT_MS` | `3000` | passed to the planner in its policy |
| `SEARCH_PLANNER_MIN_CONFIDENCE` | `0.5` | plans below it are discarded; must be 0.0…1.0 |
| `SEARCH_OBSERVABILITY_ENABLED` | `1` | read, but nothing uses it yet |

Booleans are on only when the value is exactly `1`. The values are read on the first search in each worker, not at boot: an invalid one surfaces as an `InvalidArgumentException` from that first call.

## Performance

Matching is SQL `LIKE`, one predicate per searchable field, joined with `OR`. On a large table that means:

- **`Contains` (`%term%`) cannot use a B-tree index.** The database reads every row left after the other predicates. Put a selective, indexed filter next to it (the tenant column plus `status`, a date range) so that set stays small.
- **`Prefix` (`term%`)** can use an index on that column, depending on the column's collation. **`Exact`** is still `LIKE`, without wildcards: the collation decides whether it is case-sensitive.
- **The `OR` across fields** usually stops the database from using any single-column index for the text part, even when every field is `Prefix`. Fewer searchable fields means less work.
- **Every search runs two queries:** the page, and a `COUNT(*)` with the same predicates. Both pay for the scan.
- **Deep pages cost more:** pagination is `OFFSET`, so at 20 rows a page, page 500 reads and discards about 10 000 rows first. See [Pagination](pagination.md).
- **Ranking is cheap and shallow:** it runs in PHP over the page only, so it costs nothing at scale and also cannot surface a better match from another page.
- **Multi-word queries are one substring.** `red lamp` will not find `lamp, red`.

If you need word-level matching, stemming or ranking across the whole match set at millions of rows, this package does not provide it.

## Watch out for

- **The backend is not wired.** Out of the box every search throws `SearchBackendException` ("Backend does not support index"). Bind a backend first.
- **No tenant context means tenant `default`.** A tenant index searched from a CLI command or a job without a tenant context searches `tenant_id = 'default'`, which is usually empty. Run it under the tenant (`bin/semitexa tenant:run <tenant> …`).
- **A non-numeric value on a `Number` filter is refused.** `price:abc` (or `'price' => 'abc'`) throws `SearchValidationException` naming the field.
- **A query sorts within the page.** With a query, the relevance score reorders the page and overrides your `SortClause` there. Sorting by price while searching by text gives a page of rows chosen by price and ordered by score.
- **`column` is used twice.** It is the SQL column and the key looked up in each fetched row. If your row's `toArray()` returns camelCase keys (`createdAt`) while the column is `created_at`, ranking reads nothing (score `0`) and the field is missing from `hits[].fields`. An object without `toArray()` is cast with `(array)`, where private and protected properties get mangled keys and are never found.
- **Rows without `id` or `uuid`** get a `documentId` that is only an in-memory object id, different on every request.
- **`query: ''` throws.** `SearchRequest` requires `null` or a non-empty query; whitespace-only queries are accepted and search for the spaces.
- **`searchText()` ignores `SEARCH_DEFAULT_LIMIT`.** Its own `$limit` defaults to 20 and is always passed on; a value above `SEARCH_MAX_RESULT_LIMIT` throws `SearchValidationException`.
- **Hand-built requests skip the limits.** `new SearchRequest(limit: 100000)` passed to `search()` is not checked against `SEARCH_MAX_RESULT_LIMIT`. Build requests from user input through `SearchRequestFactory`.
- **Sorts on unknown or non-sortable fields vanish** instead of failing. And once a request has any sort, `defaultSort` is not used, even if every clause was skipped.
- **The escaping assumes `\` is the `LIKE` escape character.** No `ESCAPE` clause is sent. Many databases use it by default, not all; on one that has no default escape character, a user's `%` or `_` becomes a literal backslash plus a wildcard.
- **Turning the planner on changes nothing yet.** `SEARCH_PLANNER_ENABLED=1` with `plannerEnabled: true` runs the no-op planner; results are identical.
