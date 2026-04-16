---
id: routing/public-endpoint
section: routing
slug: public-endpoint
title: Public Endpoint
summary: Every endpoint is private by default. "#[PublicEndpoint]" is the explicit opt-in for anonymous access.
order: 70
locale: en
status: canonical
keywords:
  - "#[PublicEndpoint]"
  - default private
  - 401 Unauthorized
  - Authorizer
---
# Public Endpoint

Semitexa is closed by default: every payload requires authentication unless you explicitly opt it into anonymous access with `#[PublicEndpoint]`.

## How it works

The access policy resolver inspects payload attributes at boot. If `#[PublicEndpoint]` is present, the route is marked public; otherwise the authorizer treats guest access as AuthenticationRequired and the pipeline returns 401 before the handler runs.

## Why this matters

This flips the usual risk profile. Teams do not have to remember to secure every endpoint one by one. The safe default is built in, and public exposure becomes a deliberate code review event.
