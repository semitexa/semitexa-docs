---
id: auth/protected
section: auth
slug: protected
title: Protected Route
summary: Add one attribute to any route and the framework enforces access — 403 returned automatically.
order: 50
locale: en
status: published
keywords:
  - "#[RequiresPermission]"
  - "#[PublicEndpoint]"
  - guard chain
  - 403 response
---
# Protected Route

Add one attribute to any route and the framework enforces access — 403 returned automatically.

## How it works

The guard chain runs before the handler for every non-public route. `#[RequiresPermission]` on the payload declares the required permission slug. The chain evaluates the resolved principal against that slug and either allows the request through, returns 401 for unauthenticated subjects, or returns 403 for authenticated subjects without the required grant. `#[PublicEndpoint]` bypasses the chain entirely.

## Why this matters

Access control declared on the payload is reviewable and enforced uniformly. There is no handler code that checks permissions manually, no way to forget the check in one handler but not another, and no logic duplication when two handlers share the same protection rule.
