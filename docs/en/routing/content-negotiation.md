---
id: routing/content-negotiation
section: routing
slug: content-negotiation
title: Content Negotiation
summary: One endpoint, multiple response formats — automatically.
order: 60
locale: en
status: canonical
keywords:
  - produces
  - Accept header
  - "?_format= override"
  - ContentNegotiator
---
# Content Negotiation

A single endpoint serves JSON, HTML, or other formats depending on the Accept header or `?_format=` query parameter.

## How it works

The `produces` array on the payload's access attribute (`#[AsPublicPayload]`, `#[AsProtectedPayload]`, or `#[AsServicePayload]`) declares which Content-Types the endpoint supports. The framework negotiates the best match against the client Accept header and selects the appropriate response serializer.

## Why this matters

Content negotiation lets one route serve both browser and API clients. The handler stays format-agnostic — it populates the resource DTO, and the framework handles serialization.
