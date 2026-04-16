---
id: platform/tenancy-resolution
section: platform
slug: tenancy-resolution
title: Tenant Context Resolution
summary: See how Semitexa resolves the active tenant from subdomain, header, path, or query input before the rest of the platform runs.
order: 10
locale: en
status: canonical
keywords:
  - HeaderStrategy
  - SubdomainStrategy
  - PathStrategy
  - QueryParamStrategy
  - resolver chain
---
# Tenant Context Resolution

Semitexa decides the active tenant before configuration, data access, queues, and rendering continue downstream.

## How it works

The resolver chain tries the configured strategies in priority order. Each strategy inspects one transport signal -- subdomain, request header, path segment, or query parameter. The first match wins and becomes the tenant context for the rest of the execution.

## Why this matters

If tenant resolution is ambiguous, every "isolated" layer above it becomes unreliable. That is why this boundary deserves explicit design -- the resolver chain makes the decision visible and deterministic instead of relying on implicit state.
