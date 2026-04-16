---
id: api/active-version
section: api
slug: active-version
title: Active Version
summary: The current collection endpoint with a clean X-Api-Version header and no deprecation noise.
order: 30
locale: en
status: published
keywords:
  - "#[ApiVersion]"
  - X-Api-Version
  - active lifecycle
---
# Active Version

The active version should feel intentionally boring: same response shape, stable metadata, and no sunset chatter for clients to parse around.

## How it works

`#[ApiVersion]` on the Payload DTO declares the version string and lifecycle state. When the state is active, the framework emits `X-Api-Version` on every response and nothing else. No `Deprecation` header, no `Sunset` header — just a stable version token that makes the serving contract traceable in logs and observability tooling.

## Why this matters

Clients get stable version traceability without deprecation churn. Migration pressure should disappear once a client is on the supported path. The JSON contract is identical in shape to older versions, but the lifecycle signal is clean — which is the expected steady state for any API route that is not under retirement pressure.
