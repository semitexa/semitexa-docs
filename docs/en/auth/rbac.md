---
id: auth/rbac
section: auth
slug: rbac
title: RBAC
summary: Hybrid RBAC with coarse-grained capabilities, exact permission slugs, and module-owned permission catalogs.
order: 70
locale: en
status: published
keywords:
  - "#[RequiresCapability]"
  - "#[RequiresPermission]"
  - CapabilityRegistry
  - PermissionProviderInterface
---
# RBAC

Hybrid RBAC: bitmask-backed capabilities for broad checks, slug permissions for exact business rules, and module-owned catalogs behind one authorizer.

## How it works

Semitexa separates two authorization layers. Capabilities are coarse-grained platform rights stored as bitmasks, checked with `#[RequiresCapability]`. Permission slugs are fine-grained business rules registered by any module implementing `PermissionProviderInterface` and checked with `#[RequiresPermission]`. The `Authorizer` resolves both through the `SubjectGrantResolver` so handlers never touch the grant resolution logic directly.

## Why this matters

A single permission model that forces every module to share one storage schema becomes a coupling problem as the application grows. Separating capabilities from slug permissions lets each module own its permission catalog, extend the authorization surface without modifying core tables, and still share one authorizer that keeps the check consistent across the whole application.
