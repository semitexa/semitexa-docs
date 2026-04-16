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

`AsPayload` path values support `env::VAR::/fallback` syntax. During route discovery, Semitexa resolves the env key first and falls back to the inline path when the variable is absent.

## Why this matters

This gives deployment flexibility without losing the architectural advantage of payload-owned routes. The route remains reviewable in code, but environment-specific URL decisions stop forcing PHP edits.
