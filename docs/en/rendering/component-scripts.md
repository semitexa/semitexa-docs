---
id: rendering/component-scripts
section: rendering
slug: component-scripts
title: Component Script Assets
summary: A Semitexa SSR component can own its optional enhancement asset, so behavior travels with the component instead of leaking into page-level glue.
order: 70
locale: en
status: published
keywords:
  - "#[AsComponent]"
  - script
  - SemitexaComponent.register()
  - auto-require
  - SSR component root
---
# Component Script Assets

No more "remember to include the JS somewhere on this page". If a component needs optional client enhancement, the contract lives on the component itself. A Semitexa SSR component can own its optional enhancement asset, so behavior travels with the component instead of leaking into page-level glue.

## How it works

The `script` key in `#[AsComponent]` declares the canonical asset that enhances this component. The component renderer auto-requires that script only when the component actually appears on the page. On the client side, `SemitexaComponent.register()` mounts the behavior per component root, so one script safely enhances many instances.

## The rules

- The script is declared on the component contract, not remembered manually by the page.
- The asset loads only if that component actually rendered on the page.
- The runtime mounts behavior per component root, so one script can safely enhance many instances.
- The script remains optional enhancement, not a second rendering authority.
- The component keeps owning its surface: Twig for HTML, optional script for progressive enhancement.

## Key mechanisms

- **`script`** — canonical asset key declared directly in `#[AsComponent]`.
- **`auto-require`** — ComponentRenderer requires the runtime and the asset only when the component appears.
- **`SemitexaComponent.register()`** — client behavior registers once and mounts each rendered component root independently.
- **No page glue** — feature pages stop manually remembering which component enhancement script to include.

## Why this matters

Page-level script includes create implicit dependencies between templates and JavaScript files. When a component moves to a different page, the include has to move too. Declaring the script on the component class makes the dependency explicit and co-located, so the component can render anywhere without extra wiring.
