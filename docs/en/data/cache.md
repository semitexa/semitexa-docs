---
id: data/cache
section: data
slug: cache
title: Cache
summary: Keep expensive results with CacheManagerInterface — per-worker memory or shared Redis, TTLs, single-flight remember(), namespaces, tags, and tenant isolation.
order: 110
locale: en
status: canonical
verified_against: 2026.10.20.0340
keywords:
  - CacheManagerInterface
  - remember()
  - flushTags()
  - withNamespace()
  - CacheScope
  - CACHE_DRIVER
  - single-flight
---

# Cache

`semitexa/cache` keeps a computed value for a while so the next request does not compute it again. It is installed in every project. Use it for results that are expensive to build and stay valid for at least a few seconds; skip it for values that change on every read or cost less to recompute than to look up.

## Getting it

Ask for the interface with `#[InjectAsReadonly]`. You get the worker's one shared instance.

```php
use Semitexa\Cache\Domain\Contract\CacheManagerInterface;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;

#[AsService]
final class ProductCatalog
{
    #[InjectAsReadonly]
    protected CacheManagerInterface $cache;

    public function featured(): array
    {
        return $this->cache->remember(
            'featured-products',
            fn (): array => $this->loadFeatured(),   // runs only on a miss
            ttlSeconds: 600,
            tags: ['products'],
        );
    }
}
```

The whole contract:

```php
get(string $key, mixed $default = null): mixed
put(string $key, mixed $value, ?int $ttlSeconds = null, array $tags = []): void
remember(string $key, callable $resolver, ?int $ttlSeconds = null, array $tags = []): mixed
forget(string $key): void
flushTags(string ...$tags): int
flushNamespace(?string $namespace = null): int
withNamespace(string $namespace): CacheManagerInterface
withTags(string ...$tags): CacheManagerInterface
scope(CacheScope $scope): CacheManagerInterface
```

## Choosing the store

`CACHE_DRIVER` picks the store. Only two values are accepted, exactly as written; anything else (`Redis`, `memcached`) stops the cache with an `InvalidArgumentException` on first use.

| `CACHE_DRIVER` | Store | Seen by |
|---|---|---|
| `array` (default) | a PHP array inside the worker | that worker only, until it restarts |
| `redis` | Redis | every worker and every server on the same Redis |

With `redis`, the cache borrows connections from the worker's shared Redis pool (`REDIS_HOST`, `REDIS_PORT`, `REDIS_SCHEME`, `REDIS_PASSWORD`). Set `CACHE_REDIS_POOL_SIZE` and the cache opens its own pool of that size instead. Each operation borrows one connection for its whole duration, so concurrent coroutines never share a socket.

`bin/semitexa system:doctor` (check `cache.driver`) fails on an invalid driver and warns when `CACHE_DRIVER=array` runs with `APP_ENV=prod`: features that must agree across workers, such as UI event replay protection, refuse to run on a per-worker cache.

Other settings:

| Variable | Default | Effect |
|---|---|---|
| `CACHE_DEFAULT_TTL` | `300` | seconds, used when a call passes no TTL |
| `CACHE_ALLOW_FOREVER` | `0` | `1` allows `ttlSeconds: 0` (never expires) |
| `CACHE_TAGS_ENABLED` | `1` | `0` ignores tags: nothing is indexed, `flushTags()` removes nothing and returns `0` |
| `CACHE_PREFIX` | `semitexa` | first segment of every key |
| `CACHE_APP` | `APP_NAME`, else `app` | second segment |
| `CACHE_ENV` | `APP_ENV`, else `prod` | third segment |
| `CACHE_SIGNING_KEY` | unset | allows caching objects (see below) |

Two applications on one Redis stay apart as long as their prefix, app or env differs.

## Reading and writing

```php
$this->cache->put('rates:eur', $rates, ttlSeconds: 60);
$rates = $this->cache->get('rates:eur', []);   // [] on a miss
$this->cache->forget('rates:eur');
```

- **Keys:** any non-empty string without a NUL byte. Colons are fine.
- **TTL:** `null` means `CACHE_DEFAULT_TTL`. A negative TTL throws. `0` means forever and throws unless `CACHE_ALLOW_FOREVER=1`. An expired entry reads as a miss and is deleted when it is read.
- **Values:** scalars, `null`, and arrays of those are stored as JSON. An object anywhere in the value — a `DateTimeImmutable`, an enum, a DTO inside an array — needs `CACHE_SIGNING_KEY`; without it `put()` throws `CacheSerializationException`. With it, the value is PHP-serialized and HMAC-signed, and a value whose signature does not match is refused on read. Rotating the key turns existing object entries into misses.
- **A stored `null` and a miss look alike to `get($key)`:** pass a default you can recognise (`get($key, $missing)`) when the difference matters.

## remember() and concurrent misses

`remember()` returns the cached value, or calls the resolver, stores what it returns, and returns it.

Inside a Swoole worker it is single-flight per key. When many coroutines miss the same key at once, the first one runs the resolver; the others wait for it and then read the value it stored. A burst of 8 concurrent misses calls the resolver once (`CacheRememberSingleFlightTest`). Different keys resolve independently.

The guarantee has edges:

- **Per worker.** Four workers can each run the resolver once for the same key.
- **Per manager object.** The in-flight list belongs to the instance you call. `withNamespace()`, `withTags()` and `scope()` build a new object on every call, so keep the scoped manager in a property instead of rebuilding it per call:

  ```php
  private ?CacheManagerInterface $views = null;

  private function views(): CacheManagerInterface
  {
      return $this->views ??= $this->cache->withNamespace('views');
  }
  ```

- **`null` is cached.** A resolver that returns `null` is not run again until the entry expires.
- **Exceptions.** If the leader's resolver throws, the exception reaches the leader's caller; each waiter then runs the resolver itself.
- **Outside a coroutine** (CLI, tests) there is nothing to coalesce: the resolver simply runs on a miss.
- **A concurrent `put()` or `forget()`** from elsewhere can still race the leader's write.

## Namespaces

A namespace groups keys so they can be cleared together.

```php
$views = $this->cache->withNamespace('views');
$views->put('home', $html, ttlSeconds: 120);

$views->flushNamespace();        // clears 'views' only
$this->cache->flushNamespace();  // clears the root AND every namespace of this tenant
```

- A name may contain only letters, digits, `-` and `_`; anything else throws.
- Root key `views:home` and key `home` in namespace `views` are two different entries. Forgetting or flushing one does not touch the other.
- Clearing a named namespace leaves the root and sibling namespaces alone. Clearing the root clears the whole tenant.
- `flushNamespace()` returns the number of entries removed. On Redis it walks the keys with `SCAN`.

## Tags

A tag names a group of entries across keys. Flush the tag to remove all of them.

```php
$this->cache->put('product:42', $p42, tags: ['products', 'product:42']);
$this->cache->put('product:43', $p43, tags: ['products']);

$this->cache->flushTags('product:42');              // removes product:42 → 1
$this->cache->flushTags('products', 'categories');  // every entry carrying either tag
```

What a flush removes (`CacheTagMembershipTest`, `RedisTagFlushTest`):

- **The tags an entry carries now.** An entry rewritten with different tags, or with none, is not removed by a flush of its old tag.
- **Any one of its tags is enough.** An entry written with `['one', 'two']` goes with a flush of `two`.
- **The count is what was actually deleted.** An entry already forgotten or expired is not counted. Writing the same key under the same tag three times counts once.
- **One namespace, one scope.** A flush of `shared` on the root does not reach `withNamespace('views')`, and the other way round. Each namespace is flushed through its own manager.
- **Tags are case-insensitive.** They are trimmed and lowercased before use, so `Products` and `products` are one tag.

`withTags()` adds tags to every write through that manager, and `flushTags()` on it flushes those tags together with any you pass:

```php
$catalog = $this->cache->withNamespace('catalog')->withTags('products');
$catalog->put('list:page-1', $page);   // tagged 'products'
$catalog->flushTags();                 // flushes 'products' in 'catalog'
```

On Redis, an entry changed by another writer while a flush is running is left alone, and an entry this worker cannot decode is neither deleted nor dropped from the tag. Each tag set expires with its longest-lived member, so tags nobody flushes do not grow forever.

## Tenants

Every key carries a tenant segment. With `semitexa/tenancy` installed, it comes from the current tenant context, so two tenants writing `featured-products` get two entries. Without a tenant, or for tenant `default`, every request shares `tenant:default`.

For data that belongs to no tenant, use the global scope:

```php
use Semitexa\Cache\Domain\Enum\CacheScope;

$global = $this->cache->scope(CacheScope::Global);
$global->put('exchange-rates', $rates, ttlSeconds: 3600);
```

Global and tenant entries do not see each other, even under the same key: the global scope uses the segment `scope:global`, tenants `tenant:<id>`. Flushes stay inside the tenant (or the global scope) they were called in. A project can replace how the tenant segment is found by binding its own `CacheNamespaceResolverInterface` (`#[SatisfiesServiceContract]`).

## Across workers

| | `array` | `redis` |
|---|---|---|
| A `put()` in worker 1 is visible in worker 2 | no | yes |
| Survives a worker restart or recycle | no | yes, until its TTL |
| `forget()` / flushes reach other workers | no | yes |
| Redis unreachable | — | `get()` is a miss, untagged `put()` keeps nothing; `forget()` and flushes throw |

An invalidation that cannot reach Redis throws on purpose: one that silently did nothing would serve stale data once Redis is back.

## Commands

```bash
bin/semitexa cache:forget <key> [<namespace>]
bin/semitexa cache:tags:flush <tag>...
bin/semitexa cache:flush [--namespace=<name>] [--scope=tenant|global]
```

They act on the root namespace of the current tenant unless told otherwise (`bin/semitexa tenant:run <tenant> cache:flush` for another tenant), through the same manager and resolver as the workers; `cache:tags:flush` has no namespace option. They run in their own CLI process, so with `CACHE_DRIVER=array` they only see that process's empty cache and change nothing in the running workers. `cache:clear` is a different command: it clears compiled templates under `var/cache` and cycles the workers; with `--no-reload` it clears the disk only, and running workers keep the templates they already compiled. It never touches Redis entries, though cycling the workers does empty an `array` cache.

## Watch out for

- **`array` in production.** Each worker has its own copy, a write in one is invisible to the others, and `forget()` only clears the worker that ran it.
- **Expired entries in `array` stay in memory until something reads them** or a flush clears them; the worker's memory is the only limit.
- **`null` and `get()`.** `remember()` keeps a `null` its resolver returned, but `get($key)` cannot tell a stored `null` from a miss: pass a default you can recognise (`get($key, $missing)`) when the difference matters.
- **Tagged writes during an outage.** If the tag index cannot be written, the entry is removed again (an entry no tag knows would survive every flush of its tags); the call itself does not fail. The outage is logged once, by the Redis circuit breaker, not once per write. Any other exception from a tag index is a bug and is thrown.
- **`CACHE_DEFAULT_TTL=0` means forever.** It is refused (every cache call fails, and `system:doctor` reports `cache.default-ttl`) unless `CACHE_ALLOW_FOREVER=1`.
- **Booleans.** Write `CACHE_ALLOW_FOREVER` and `CACHE_TAGS_ENABLED` as `1` and `0`: every reader takes those, while not every one takes `true`, `yes` or `on`.
