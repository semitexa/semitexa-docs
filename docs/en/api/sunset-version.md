---
id: api/sunset-version
section: api
slug: sunset-version
title: Sunset Version
summary: A deprecated product endpoint that emits both Deprecation and Sunset headers.
order: 40
locale: en
status: published
keywords:
  - "#[ApiVersion]"
  - Deprecation
  - Sunset
  - X-Api-Version
---
# Sunset Version

Deprecated API versions should still be understandable: the response body is intact, but the headers clearly say the contract is on the way out.

## How it works

Setting the lifecycle to deprecated on `#[ApiVersion]` makes the framework emit three additional headers alongside the normal `X-Api-Version`: `Deprecation` carries the date the contract entered retirement, and `Sunset` carries the date it will stop being served. The response body is unchanged — the route still resolves successfully.

## Why this matters

Retirement notice should be explicit without immediately breaking integrations. `X-Api-Version` makes the serving contract version visible in every response, `Deprecation` tells consumers when to start migrating, and `Sunset` gives them a hard deadline. Observability and support workflows need a precise contract version, not guesses from the URL path alone.
