---
id: routing/rate-limiting
section: routing
slug: rate-limiting
title: Rate Limiting
summary: Limit a route with #[RateLimit] or any action with RateLimiter — per address or per user, held across workers, answered with 429 and Retry-After.
order: 100
locale: en
status: canonical
verified_against: 2026.10.20.0340
keywords:
  - RateLimit
  - RateLimiter
  - "429"
  - Retry-After
  - RATE_LIMIT_STORE
---

# Rate Limiting

## On a route

```php
use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Attribute\RateLimit;
use Semitexa\Core\RateLimit\RateLimitKey;

#[AsPublicPayload(path: '/api/search', methods: ['GET'], responseWith: SearchResource::class)]
#[RateLimit(30, 60)]                         // 30 a minute per client address
#[RateLimit(1000, 3600, by: RateLimitKey::User)] // and 1000 an hour per signed-in user
final class SearchPayload {}
```

One request over a limit answers **429** with `Retry-After` (seconds) and `RateLimit-Limit`, `RateLimit-Remaining`, `RateLimit-Reset`. The handler does not run.

- **`by`:** `RateLimitKey::Ip` (default) counts per client address. An IPv6 client counts with its whole /64, so rotating addresses inside it does not help. `RateLimitKey::User` counts per signed-in user; a guest is counted by address.
- **`bucket`:** requests counted together. It defaults to the payload class, so two routes never share a counter by accident. Give two routes the same bucket to limit them as one.
- **Several limits:** each must hold. A request refused by one is taken back from the others, so a client blocked for a minute does not use up its hour.
- **When it runs:** after authorization, before CSRF and the handler. A request refused for permissions is not counted. Neither is a request that fails validation, since the payload is built first.
- **Live regions:** re-rendering a region for an already served request is not a new request and is not counted.

The window slides: a request is judged by the current minute's count plus the previous minute's, weighted by how much of it still lies in the last 60 seconds. A burst at the edge of a minute cannot pass twice the limit, and `Retry-After` is exact: one more request fits at that moment, not before.

## In code

A form action, a HUG event or a service limits itself with `RateLimiter`:

```php
#[InjectAsMutable]
protected RateLimiter $limiter;

$decision = $this->limiter->attempt('password-reset', 5, 900);   // 5 per 15 minutes per address
if (!$decision->allowed) {
    return UiFormSubmitActionResult::rejected("Too many attempts. Try again in {$decision->retryAfter} s.");
}
```

`attempt()` counts and decides; `enforce()` throws `RateLimitException` instead (a 429 on an HTTP route). A string in place of the key is a subject of your own: `attempt('login', 10, 300, $email)` limits per account.

A form action returns `rejected(...)` rather than throwing: the HUG dispatcher turns every exception from an action into a generic failure, so a thrown 429 would never reach the visitor as one.

## Where the counts live

A limit is only a limit if every worker counts into the same place. `RATE_LIMIT_STORE` chooses:

| Value | Store | Holds across |
|---|---|---|
| `auto` (default) | Redis when `REDIS_HOST` is set, else a shared table | Redis: every server. Table: every worker of this server |
| `redis` | Redis (one script per request) | every server |
| `table` | a `Swoole\Table` created before the workers fork, `RATE_LIMIT_TABLE_SIZE` rows (65536) | every worker of this server |
| `memory` | each worker's own memory | one worker only: 4 workers admit 4 times a limit |

When Redis is unreachable, the request is let through: a rate limit that takes the site down with Redis would be the bigger outage. A full table first deletes the counters of finished windows, and what it still cannot hold is counted in the worker that took the request (a subject stays there for the rest of its window), so a limit holds per worker until the table frees up. Only once that worker also holds 100 000 counters is a request let through uncounted. The log says so once a minute; raise `RATE_LIMIT_TABLE_SIZE` or use Redis. Worker 0 sweeps the table every five seconds. `bin/semitexa system:doctor` (`rate-limit.store`) warns when the configuration cannot hold a limit for the whole server.

## The client address behind a proxy

The address is the connecting peer. Only when that peer is a trusted proxy (loopback, or listed in `TRUSTED_PROXIES`) is `X-Forwarded-For` read, from the right, skipping hops that are trusted proxies too: the first other hop is the client. What a client writes into the header itself proves nothing, because it only adds to the left of the list. A wrong `TRUSTED_PROXIES` puts every visitor behind the proxy into one counter, or lets clients name their own address; `ForwardedProxyDoctorCheck` checks the setting.
