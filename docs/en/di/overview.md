---
id: di/overview
section: di
slug: overview
title: DI Canon
summary: One canonical DI path for container-managed classes: explicit properties, explicit lifecycles, deterministic boot.
order: 10
locale: en
status: canonical
keywords:
  - single-path DI
  - "#[InjectAsReadonly]"
  - "#[InjectAsMutable]"
  - boot-time validation
---
# DI Canon

Semitexa has one canonical dependency injection model for container-managed framework objects: protected property injection via explicit attributes.

## How it works

The container creates the object, injects configuration and service properties, validates the whole graph at boot, and rejects hidden or competing dependency paths. Readonly services are shared per worker, execution-scoped services are cloned per execution, factories stay explicit, and contracts resolve through declared ownership metadata.

## Why this matters

The goal is not DI flexibility. The goal is deterministic behavior in a long-running runtime. When the framework uses one visible injection path, boot stays reviewable, graceful reloads stay reliable, and large refactors stop failing because one class quietly used a different dependency pattern.
