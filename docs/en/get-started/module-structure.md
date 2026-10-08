---
id: get-started/module-structure
section: get-started
slug: module-structure
title: Module Structure
summary: The minimal Semitexa module is a typed HTTP spine of payload, handler, resource, and template.
order: 30
locale: en
status: canonical
verified_against: 2026.10.03.1952
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

A project module lives in `src/modules/<Module>/`. Its PHP code is under `src/modules/<Module>/src/`, in the namespace `App\Modules\<Module>\`. `LocalModuleAutoloadRegistrar` maps that namespace at boot, so a new module needs no `composer dump-autoload` and no registry entry. `bin/semitexa make:module --name=<Module> --target=custom --write` creates the skeleton. The starter `Hello` module in a new project is a working example.

All paths below are relative to `src/modules/<Module>/src/`.

---

## Payload: `Application/Payload/{Type}/`

| Subfolder | Purpose | Attribute / usage |
|-----------|---------|-------------------|
| **Request** | HTTP request DTOs (route + methods) | `#[AsPublicPayload(path, methods, responseWith)]` (or `#[AsProtectedPayload]` / `#[AsServicePayload]`); discovered from the attribute, no registration step |
| **Session** | Session segment DTOs | `#[SessionSegment('name')]`; `SessionInterface::getPayload()` / `setPayload()` |
| **Event** | Event DTOs for dispatch | Used with `EventDispatcher::create(EventClass::class, [...])` and `dispatch()` |
| **Part** | Reusable payload traits | `#[AsPayloadPart]` |

**Namespaces:** `App\Modules\{Module}\Application\Payload\Request\`, `...\Payload\Session\`, `...\Payload\Event\`.

Do **not** put these in `Application/Session/` or other ad-hoc folders. Use **`Application/Payload/Request/`**, **`Payload/Session/`**, **`Payload/Event/`**, **`Payload/Part/`** only.

Request DTOs declare access through one of `#[AsPublicPayload]` / `#[AsProtectedPayload]` / `#[AsServicePayload]`, and finer requirements through `#[RequiresPermission('name')]` or `#[RequiresCapability('name')]`.

---

## Handler: `Application/Handler/{Type}/`

| Subfolder | Purpose | Attribute |
|-----------|---------|-----------|
| **PayloadHandler** | HTTP handlers (payload → resource) | `#[AsPayloadHandler(payload: ..., resource: ...)]` |
| **SlotHandler** | Logic for a page slot | `#[AsSlotHandler(slot: ...)]` |
| **DomainListener** | Domain event listeners (sync/async/queued) | `#[AsEventListener(event: ..., execution: ...)]` |

Pipeline listeners (`#[AsPipelineListener]`) and Swoole server lifecycle listeners (`#[AsServerLifecycleListener]`) are services, not handlers: put them under `Application/Service/`, for example `Application/Service/Server/Lifecycle/`.

---

## Full layout

```
src/modules/<Module>/
├── composer.json             # optional
├── tests/
└── src/
    └── Application/
        ├── Payload/
        │   ├── Request/          # HTTP request DTOs
        │   ├── Session/          # Session segment DTOs
        │   ├── Event/            # Event DTOs
        │   └── Part/             # Payload part traits
        ├── Handler/
        │   ├── PayloadHandler/   # HTTP handlers
        │   ├── SlotHandler/      # Slot handlers
        │   └── DomainListener/   # Domain event listeners
        ├── Resource/
        │   ├── Response/         # Response DTOs
        │   └── Slot/             # Slot resources
        ├── Service/              # optional; also pipeline and server lifecycle listeners
        ├── Console/Command/      # optional; console commands
        ├── Static/               # optional; css/, js/, assets.json
        └── View/
            ├── locales/
            └── templates/
```

`src/Domain/` sits next to `src/Application/` for models, repository interfaces and domain services. The complete allowlist, which `ai:verify` enforces, is in the module-structure reference (`MODULE_STRUCTURE.md` in `semitexa/docs`).
