---
id: routing/public-endpoint
section: routing
slug: public-endpoint
title: Public Payload
summary: Anonymous endpoints opt in explicitly, with #[AsPublicPayload] or a public feed that says why. Every other payload requires authentication.
order: 80
locale: en
status: canonical
verified_against: 2026.11.24.1203
keywords:
  - "#[AsPublicPayload]"
  - "#[AsProtectedPayload]"
  - "#[AsServicePayload]"
  - "#[AsPublicFeed]"
  - "#[AsCollectionFeed]"
  - lint:access
  - 401 Unauthorized
  - Authorizer
---
# Public Payload

Semitexa is closed by default. A payload becomes a route only through a route attribute, and each one names its own access type: there is no default to fall back to. Anonymous access is always an explicit opt-in.

## Which attributes make a route public

Every route attribute extends `AbstractPayloadRoute` and answers `getAccessType()` with `Public`, `Protected` or `Service`:

| Attribute | Package | Access |
|---|---|---|
| `#[AsPublicPayload]` | core | Public |
| `#[AsProtectedPayload]` | authorization | Protected |
| `#[AsServicePayload]` | authorization | Service |
| `#[AsPublicFeed]` | crud | Public, with a required `reason:` |
| `#[AsCollectionFeed]` | crud | Protected by default; Public with `access: PayloadAccessType::Public` and a `reason:`; never Service |
| `#[AsProtectedFeed]`, `#[AsCrud]`, `#[AsSettingsPage]` | crud | Protected |

A review for anonymous exposure of the framework's own attributes therefore looks for three forms, not one: `AsPublicPayload`, `AsPublicFeed`, and `PayloadAccessType::Public` inside an `#[AsCollectionFeed]`. A project or package that defines its own route attribute (any class extending `AbstractPayloadRoute`) adds a fourth: check what its `getAccessType()` returns. Both public feed forms refuse an empty `reason`, so each says in the code why anyone may read its rows. A collection feed turns public through its `access:` argument alone, with no change to the attribute's name, so read that argument in a diff.

## How it works

`#[AsProtectedPayload]` requires a User-domain principal and returns 401 to a guest before the handler runs. `#[AsServicePayload]` requires a Service-domain principal (a verified webhook signature, a machine token or a partner credential) and returns 401 on missing or wrong-domain credentials. `#[AsPublicPayload]` and the public feeds let a guest through.

Discovery reads each route's access type when the server boots. The rest of the access metadata is checked on the first request to each payload class, and a policy that passes is cached. Two access attributes on one class, or a public payload that also requires a permission or a capability, therefore boots cleanly and then fails every request to that route. `bin/semitexa lint:access` runs the same check over every route with no request, and `ai:verify` runs it whenever a payload changes, so the mistake is caught before it ships.

The nearest class decides. A payload that extends another and declares its own route attribute takes that attribute's access type; the parent's is not counted. Permission and capability requirements gather over the whole chain. A public subclass of a payload that requires a permission fails every request, and `lint:access` reports it; a public subclass of one that requires nothing is public.

## A payload with no access attribute

A payload class with no route attribute is not a route, so no request reaches it. If a handler is written for it, the server refuses to boot and names the payload. Either way a missing attribute closes the endpoint and never exposes it.

## See also

- [Protected Route](../auth/protected.md) — `#[RequiresPermission]` and `#[RequiresCapability]` on protected payloads.
- [Post-Hardening Migration Guide](../migration/post-hardening.md) — migrating from the legacy access model.
