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
  - "#[AsUiBehavior]"
  - inline script
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

## Not an inline `<script>`

Writing the script inside the block's own template is the obvious thing to do, and it works — until the block starts arriving over SSE. Markup that arrives in a live document changes what a script tag means, in three ways that nothing announces:

- **It is inert.** A script parsed out of a fragment and inserted does not run. It has to be re-created, and re-created with *this* document's CSP nonce rather than the one it was parsed with. Under a strict `script-src` the page then works while reporting a blocked script on every arrival.
- **It runs more than once.** Every arrival re-creates it, so every binding it makes has to be idempotent. A listener attached to `document` accumulates silently, once per delivery.
- **`DOMContentLoaded` has already fired.** Code waiting for it never runs. A field-autocomplete partial that bound on that event simply stopped binding for any block that arrived by SSE.

Two mechanisms avoid all three, and `lint:deferred-twig` reports an inline `<script>` in a deferred template naming them:

- **`clientModules`** for code that belongs to this block — a module served from your own origin, injected once, initialized on `semitexa:block:rendered`.
- **`#[AsUiBehavior]`** for an interaction with no server state — a dropdown, a modal, tabs, a tooltip. The behavior runtime watches the document with a single `MutationObserver` and connects late-arriving markup wherever it appears, so timing stops being your problem.

`make:page --with-assets` scaffolds the module shape rather than an inline block for the same reason.

## Why this matters

Without a structured mechanism, deferred blocks that need JavaScript require either page-level script includes (which load unconditionally) or runtime dynamic imports that need custom timing logic. Declaring `clientModules` on the slot attribute keeps the dependency co-located and the injection lifecycle automatic.
