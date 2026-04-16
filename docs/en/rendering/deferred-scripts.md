---
id: rendering/deferred-scripts
section: rendering
slug: deferred-scripts
title: Script Injection
summary: Deferred blocks carry their own JS — injected once when the block arrives, never duplicated.
order: 80
locale: en
status: published
keywords:
  - clientModules
  - semitexa:block:rendered
  - auto-play
  - script isolation
---
# Script Injection

Deferred slots can declare client modules that are injected once when the block lands. Deferred blocks carry their own JS — injected once when the block arrives, never duplicated.

## How it works

The `clientModules` key in `#[AsSlotResource]` declares the JavaScript module paths the deferred block needs. When the block HTML arrives over SSE, the framework injects those modules into the page exactly once. The module initializes only after `semitexa:block:rendered` fires for the delivered block.

```php
#[AsSlotResource(
    handle: 'demo_deferred_scripts',
    slot: 'deferred_chart_widget',
    template: '...chart-widget.html.twig',
    deferred: true,
    clientModules: ['@project-static-semitexa-demo/deferred/chart-widget.js'],
)]
```

## Key mechanisms

- **`clientModules`** — declares the JS module paths the block needs, directly in the slot attribute.
- **`semitexa:block:rendered`** — browser event fired after the block HTML has been inserted into the DOM.
- **auto-play** — the framework handles deduplication, so the same module is not loaded twice even if the block appears multiple times.
- **script isolation** — each block's scripts activate after its own `rendered` event, keeping timing scoped to the correct block.

## Why this matters

Without a structured mechanism, deferred blocks that need JavaScript require either page-level script includes (which load unconditionally) or runtime dynamic imports that need custom timing logic. Declaring `clientModules` on the slot attribute keeps the dependency co-located and the injection lifecycle automatic.
