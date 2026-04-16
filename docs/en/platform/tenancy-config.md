---
id: platform/tenancy-config
section: platform
slug: tenancy-config
title: Per-Tenant Configuration
summary: Three demo tenants with distinct branding -- switch tenant, everything changes without if/else.
order: 20
locale: en
status: canonical
keywords:
  - TenantConfig
  - feature flags
  - branding
  - locale defaults
  - per-tenant
---
# Per-Tenant Configuration

Tenancy is not only row isolation. The active tenant changes branding, locale defaults, pricing conventions, and visible features.

## How it works

One tenant config is resolved once, then reused by rendering, component behavior, and downstream services. Brand surface, commerce defaults, interaction rules, and rendering implications all derive from that single config object instead of being scattered across controllers or templates.

## Why this matters

The important platform promise is this: the same codebase can produce multiple product surfaces without sprinkling tenant-specific if/else logic everywhere. Configuration drives the divergence, not conditional branching.
