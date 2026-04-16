---
id: platform/tenancy-isolation
section: platform
slug: tenancy-isolation
title: Data Isolation
summary: Product listing scoped by tenant -- switch tenant, list changes. Zero manual WHERE clauses.
order: 40
locale: en
status: canonical
keywords:
  - tenant_id
  - data isolation
  - automatic filtering
  - repository boundary
  - shared storage
---
# Data Isolation

Switch tenant, and the same repository calls return a different dataset without hand-written WHERE clauses.

## How it works

Tenant-scoped resources inject tenant filters automatically, so repository code stays focused on business queries. The framework applies the tenant WHERE clause at the persistence boundary. Supported strategies include shared-table filtering, connection-per-tenant switching, and schema-per-tenant isolation.

## Why this matters

This is the kind of platform guarantee that should be obvious in a demo, not hidden in docs. When the tenant changes, the dataset changes automatically -- no manual filtering and no risk of cross-tenant data leakage in business query code.
