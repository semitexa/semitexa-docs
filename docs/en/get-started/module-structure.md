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

## Canonical folder layout

This is the canonical Semitexa module layout for request payloads, handlers, and response DTOs.

---

## Payload: `Application/Payload/{Type}/`

| Subfolder | Purpose | Attribute / usage |
|-----------|---------|-------------------|
| **Request** | HTTP request DTOs (route + methods) | `#[AsPublicPayload(path, methods, responseWith)]`; require entry in `src/registry/Payloads/` |
| **Session** | Session segment DTOs | `#[SessionSegment('name')]`; `SessionInterface::getPayload()` / `setPayload()` |
| **Event** | Event DTOs for dispatch | Used with `EventDispatcher::create(EventClass::class, [...])` and `dispatch()` |

**Namespaces:** `Semitexa\Modules\{Module}\Application\Payload\Request\`, `...\Payload\Session\`, `...\Payload\Event\`.

Do **not** put these in `Application/Session/` or other ad-hoc module-root folders. Use **`Application/Payload/Request/`**, **`Payload/Session/`**, **`Payload/Event/`** only.

Request DTOs declare access through one of `#[AsPublicPayload]` / `#[AsProtectedPayload]` / `#[AsServicePayload]`, and finer requirements through `#[RequiresPermission('name')]` or `#[RequiresCapability('name')]`.

---

## Handler: `Application/Handler/{Type}/`

| Subfolder | Purpose | Attribute |
|-----------|---------|-----------|
| **PayloadHandler** | HTTP handlers (payload → resource) | `#[AsPayloadHandler(payload: ..., resource: ...)]` |
| **System** | Pipeline listeners (Auth/Access phases) | `#[AsPipelineListener(phase: ..., priority: ...)]` |
| **Server** | Swoole server lifecycle hooks including pre-fork bootstrap | `#[AsServerLifecycleListener(phase: ..., priority: ...)]` |
| **DomainListener** | Domain event listeners (sync/async/queued) | `#[AsEventListener(event: ..., execution: ...)]` |

---

## Full layout

```
Application/
├── Payload/
│   ├── Request/          # HTTP request DTOs
│   ├── Session/          # Session segment DTOs
│   └── Event/            # Event DTOs
├── Handler/
│   ├── PayloadHandler/   # HTTP handlers
│   ├── System/           # Pipeline listeners
│   └── DomainListener/   # Domain event listeners
├── Server/               # Swoole server lifecycle listeners
├── Resource/             # Response DTOs
├── View/templates/
└── Service/              # optional
```
