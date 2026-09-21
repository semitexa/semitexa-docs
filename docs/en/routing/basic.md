---
id: routing/basic
section: routing
slug: basic
title: Basic Route
summary: Define a route with one access attribute on the payload — no XML, no YAML, no config files.
order: 10
locale: en
status: canonical
verified_against: 2026.09.19.1020
keywords:
  - "#[AsProtectedPayload]"
  - "#[AsPublicPayload]"
  - "#[AsServicePayload]"
  - env::VAR_NAME::/default/path
  - responseWith
  - ClassDiscovery
  - path
  - methods
---
# Basic Route

A single access attribute on a PHP class creates a fully routed HTTP endpoint — no XML, no YAML, no config files. Access is explicit at the type level: every payload picks one of `#[AsPublicPayload]`, `#[AsProtectedPayload]`, or `#[AsServicePayload]`.

```semitexa-diagram
title: HTTP request lifecycle
node: request | HTTP request | Method, path, headers | 0 | 0
node: payload | Payload | Route and input contract | 1 | 0
node: handler | Handler | Runs the use case | 2 | 0
node: resource | Resource | Shapes the response | 3 | 0
node: template | Template | Produces final HTML | 4 | 0
edge: request -> payload | match and hydrate
edge: payload -> handler | dispatch
edge: handler -> resource | populate
edge: resource -> template | render
```

## How it works

The framework scans the Composer classmap for classes carrying any of the three access attributes, extracts path and method metadata, resolves `env::` placeholders if present, and registers routes at boot. The route compiler then turns path patterns into optimized regex matchers cached in memory.

## Why this matters

Keeping route definitions co-located with their request DTOs means a reader can understand what an endpoint accepts and where it lives by reading a single file. The access attribute on the same line declares the security stance — anonymous, user-authenticated, or service-domain — so route review and security review collapse into one read. `env::` syntax lets operations move a route without reopening PHP code when deployment topology demands it.
