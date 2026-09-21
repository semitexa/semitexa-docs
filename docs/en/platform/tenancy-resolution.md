---
id: platform/tenancy-resolution
section: platform
slug: tenancy-resolution
title: Tenant Context Resolution
summary: See how Semitexa resolves the active tenant from subdomain, header, path, or query input before the rest of the platform runs.
order: 10
locale: en
status: canonical
verified_against: 2026.09.19.1020
keywords:
  - HeaderStrategy
  - SubdomainStrategy
  - PathStrategy
  - QueryParamStrategy
  - resolver chain
---
# Tenant Context Resolution

Semitexa decides the active tenant before configuration, data access, queues, and rendering continue downstream.

```semitexa-diagram
title: Tenant context propagation
node: signal | Request signal | Host, header, path, query | 0 | 0
node: resolver | Resolver chain | First matching strategy wins | 1 | 0
node: tenant | Tenant identity | Stable active tenant id | 2 | 0
node: context | Execution context | Carries tenant downstream | 3 | 0
node: layers | Isolated layers | Config, data, jobs, UI | 4 | 0
edge: signal -> resolver | inspect
edge: resolver -> tenant | resolve
edge: tenant -> context | activate
edge: context -> layers | scope
```

## How it works

The resolver chain tries the configured strategies in priority order. Each strategy inspects one transport signal -- subdomain, request header, path segment, or query parameter. The first match wins and becomes the tenant context for the rest of the execution.

## Why this matters

If tenant resolution is ambiguous, every "isolated" layer above it becomes unreliable. That is why this boundary deserves explicit design -- the resolver chain makes the decision visible and deterministic instead of relying on implicit state.
