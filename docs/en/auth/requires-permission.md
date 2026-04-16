---
id: auth/requires-permission
section: auth
slug: requires-permission
title: Requires Permission
summary: Declare one permission slug on the payload and let the framework enforce it before your handler runs.
order: 60
locale: en
status: published
keywords:
  - "#[RequiresPermission]"
  - 401 Unauthorized
  - 403 Forbidden
  - guard chain
---
# Requires Permission

Declare one permission slug on the payload and let the framework enforce it before your handler runs.

## How it works

Place `#[RequiresPermission('slug')]` on any payload class. The guard chain intercepts every request to that route, resolves the current principal, and checks whether the permission is granted. Guests receive 401, authenticated subjects without the grant receive 403, and subjects with the grant reach the handler normally.

## Why this matters

Access control should be declarative. When the permission requirement lives on the payload, it is visible to reviewers alongside the route definition, enforced consistently by the framework without any handler code, and impossible to accidentally skip by forgetting a manual check.
