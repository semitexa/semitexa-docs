---
id: routing/env-route-override
section: routing
slug: env-route-override
title: Env Route Override
summary: Keep the payload as the route source of truth while allowing operations to remap the public URL through .env.
order: 30
locale: en
status: canonical
keywords:
  - env::VAR::/fallback
  - path override
  - .env-driven routing
  - same payload boundary
---
# Env Route Override

A payload can keep the route contract in PHP while still letting operations move the public URL through `.env`.

## How it works

The `path:` argument on the payload's access attribute supports `env::VAR::/fallback` syntax. During route discovery, Semitexa resolves the env key first and falls back to the inline path when the variable is absent.

## Why this matters

This gives deployment flexibility without losing the architectural advantage of payload-owned routes. The route remains reviewable in code, but environment-specific URL decisions stop forcing PHP edits.

## What can be overridden

Any string field on `#[AsPublicPayload]` can use env resolution, but the most valuable ones for routing are:

- `path`
- `name`
- `responseWith`

In practice, `path` is the main one you should expose for environment-level URL control.

## Example: keep code stable, move the URL per environment

```php
#[AsPublicPayload(
    path: 'env::DEMO_BASIC_ROUTE_PATH::/demo/routing/basic',
    methods: ['GET'],
    responseWith: DemoFeatureResource::class,
    produces: ['application/json', 'text/html'],
)]
final class BasicRoutePayload
{
}
```

`.env`:

```dotenv
DEMO_BASIC_ROUTE_PATH=/demo/http/basic-route
```

Without changing PHP code, the payload now resolves to:

```text
/demo/http/basic-route
```

If the env key is absent, Semitexa falls back to:

```text
/demo/routing/basic
```

## Guidance

- Prefer `env::VAR::default` over `env::VAR` so the route always has a safe fallback.
- Use this for operational flexibility, not as a substitute for route design.
- Keep the payload class name and handler stable even if the public URL changes.
- When the route is an SSR page, its alternate links and route discovery continue to follow the resolved payload path.
