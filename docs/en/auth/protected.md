---
id: auth/protected
section: auth
slug: protected
title: Protected Route
summary: Add one access attribute and one optional permission attribute and the framework enforces access — 401 for unauthenticated requests, 403 for unauthorized ones.
order: 50
locale: en
status: published
keywords:
  - "#[AsProtectedPayload]"
  - "#[RequiresPermission]"
  - "#[RequiresCapability]"
  - guard chain
  - 403 response
---
# Protected Route

Mark a payload with `#[AsProtectedPayload]` and the framework enforces access — 401 for unauthenticated requests, 403 for authenticated subjects without the required permission.

## How it works

The guard chain runs before the handler for every protected payload. `#[RequiresPermission]` on the payload declares the required permission slug; `#[RequiresCapability]` declares a coarse-grained capability gate. The chain evaluates the resolved principal against the declared grants and either allows the request through, returns 401 for unauthenticated subjects, or returns 403 for authenticated subjects without the required grant. `#[AsPublicPayload]` is the explicit opt-out for anonymous routes.

## Why this matters

Access control declared on the payload is reviewable and enforced uniformly. There is no handler code that checks permissions manually, no way to forget the check in one handler but not another, and no logic duplication when two handlers share the same protection rule. Because the access attribute and the permission/capability attributes co-exist on the same class, a code reviewer sees both the security stance (public / protected / service) and the fine-grained guards in one read.
