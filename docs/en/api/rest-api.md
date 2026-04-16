---
id: api/rest-api
section: api
slug: rest-api
title: REST API
summary: Classic Semitexa REST endpoints with typed payloads, versioning, and consumer-friendly response shaping.
order: 10
locale: en
status: published
keywords:
  - "#[ExternalApi]"
  - "#[ApiVersion]"
  - application/ld+json
  - fields
  - expand
---
# REST API

Semitexa REST endpoints use typed Payload DTOs as the contract boundary — no controller sprawl, no magic annotation wiring, and no separate serializer configuration step.

## How it works

Mark a Payload with `#[ExternalApi]` to opt it into the machine-facing API surface. Add `#[ApiVersion]` to set version metadata and emit `X-Api-Version` on every response. The same handler can respond to JSON, JSON-LD, and sparse field requests by reading negotiation signals from the request without branching the route.

## Why this matters

One endpoint can serve multiple API consumers without branching into separate handler trees. The contract is visible in the Payload DTO, the version is declared on the attribute, and the response shape is shaped from a typed presenter — not from ad-hoc `json_encode` calls scattered across the handler.
