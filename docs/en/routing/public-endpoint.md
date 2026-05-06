---
id: routing/public-endpoint
section: routing
slug: public-endpoint
title: Public Payload
summary: Anonymous endpoints opt in explicitly with the public access attribute. Every other payload requires authentication.
order: 70
locale: en
status: canonical
keywords:
  - "#[AsPublicPayload]"
  - "#[AsProtectedPayload]"
  - "#[AsServicePayload]"
  - 401 Unauthorized
  - Authorizer
---
# Public Payload

Semitexa is closed by default. Every payload picks one of three access attributes — `#[AsPublicPayload]`, `#[AsProtectedPayload]`, or `#[AsServicePayload]` — and the framework refuses to discover a payload that declares none. Anonymous access is the explicit `#[AsPublicPayload]` opt-in, never an implicit fallback.

## How it works

The access policy resolver inspects the payload's access attribute at boot. `#[AsPublicPayload]` marks the route as anonymous-allowed. `#[AsProtectedPayload]` requires a User-domain principal and returns 401 to guest requests before the handler runs. `#[AsServicePayload]` requires a Service-domain principal (a verified webhook signature, machine token, or partner credential) and similarly returns 401 on missing or wrong-domain credentials.

## Why this matters

The three attributes are mutually exclusive and explicit. Public exposure becomes a deliberate code-review event because the keyword `AsPublicPayload` appears at the type. There is no "default protected" fallback that someone could remove; there is no shared base attribute that could accidentally drop a route into the wrong access class. A payload that declares none of the three never reaches the route registry, so a missing attribute is a build-time discovery failure rather than a silently exposed endpoint.

## See also

- [Protected Route](../auth/protected.md) — `#[RequiresPermission]` and `#[RequiresCapability]` on protected payloads.
- [Post-Hardening Migration Guide](../migration/post-hardening.md) — migrating from the legacy access model.
