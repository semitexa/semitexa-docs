---
id: get-started/beyond-controllers
section: get-started
slug: beyond-controllers
title: Beyond Controllers
summary: Understand why Semitexa keeps transport, use case, and rendering as separate explicit responsibilities.
order: 70
locale: en
status: canonical
keywords:
  - controllers
  - payload
  - handler
  - resource
  - rendering boundary
demo_preview: concept-preview
---
# Beyond Controllers

Semitexa does not treat controllers as the universal container for transport, orchestration, and rendering.

## Canonical split

- payload owns inbound HTTP structure
- handler owns the use case
- resource owns response data and metadata
- template owns presentation

## What this avoids

- transport and business logic collapsing into one unstable class
- hidden rendering state
- ad hoc arrays passed across unclear boundaries

## Why this matters

The framework stays readable because each layer owns one job. That keeps growth additive instead of turning every new feature into another oversized controller.
