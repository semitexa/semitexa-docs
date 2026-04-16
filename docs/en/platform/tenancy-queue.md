---
id: platform/tenancy-queue
section: platform
slug: tenancy-queue
title: Queue Tenant Propagation
summary: Tenant context travels with queued jobs -- _tenant key injected automatically, restored by worker.
order: 50
locale: en
status: canonical
keywords:
  - TenantAwareJobSerializer
  - _tenant envelope
  - queue propagation
  - worker context
  - background isolation
---
# Queue Tenant Propagation

Queued jobs keep tenant context attached so background work stays scoped after the HTTP request is gone.

## How it works

The serializer wraps the message with a tenant envelope containing the full tenant context (organization, locale, theme, environment). When the worker dequeues the job, TenantAwareJobSerializer unwraps the _tenant key, restores the context, and the handler runs with the correct tenant scope -- ORM queries, templates, and downstream services all see the right tenant automatically.

## Why this matters

Without this, multi-tenant background processing quietly becomes dangerous. Jobs could execute against the wrong tenant's data or configuration. Propagating tenant context explicitly through the queue boundary eliminates that entire class of bugs.
