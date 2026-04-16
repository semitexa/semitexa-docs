---
id: platform/tenancy-layers
section: platform
slug: tenancy-layers
title: Multi-Layer Tenancy
summary: "Organization, Locale, Theme, Environment -- four independent layers compose into one TenantContext."
order: 30
locale: en
status: canonical
keywords:
  - OrganizationLayer
  - LocaleLayer
  - ThemeLayer
  - EnvironmentLayer
  - TenantContext
---
# Multi-Layer Tenancy

Tenant context is not one switch. It is a composed stack of organization, locale, theme, and environment decisions.

## How it works

Each layer resolves independently, then merges into the final context consumed by the rest of the app. Organization answers who the request belongs to. Locale answers how the product speaks. Theme answers how it looks. Environment answers where it runs. Strategies stay swappable because each one is isolated.

## Why this matters

Showing the layers separately makes the platform model understandable instead of mystical. Consumers read one composed context rather than reconstructing layer decisions themselves.
