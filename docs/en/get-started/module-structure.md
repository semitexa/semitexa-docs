---
id: get-started/module-structure
section: get-started
slug: module-structure
title: Module Structure
summary: The minimal Semitexa module is a typed HTTP spine of payload, handler, resource, and template.
order: 30
locale: en
status: canonical
keywords:
  - Payload
  - Handler
  - Resource
  - Template
  - Catalog
  - SEO
demo_preview: module-structure-files
related_documents:
  - get-started/installation
  - get-started/beyond-controllers
---
# Module Structure

A Semitexa module begins with one minimal HTTP spine:

- payload
- handler
- resource
- template

Everything else extends that path. Nothing replaces it.

## Responsibility split

## Payload

Owns the route contract and inbound data boundary.

## Handler

Owns the use case and orchestration.

## Resource

Owns response data, metadata, and render context.

## Template

Owns presentation only.

## Why this matters

First-time readers should be able to explain a module in one sentence before they learn the whole catalog. The small typed spine keeps the request path legible while the product shell can grow around it.
