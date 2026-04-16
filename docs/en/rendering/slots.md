---
id: rendering/slots
section: rendering
slug: slots
title: Slot Resources
summary: Each page region is its own resource pipeline with the same template system as the main page — no scattered partial glue, no mystery wiring.
order: 30
locale: en
status: published
keywords:
  - "#[AsSlotResource]"
  - HtmlSlotResponse
  - layout_slot()
  - SlotHandlerPipeline
  - shared Twig
---
# Slot Resources

A slot is not a fragment hack. It is a real resource with its own handler pipeline, template, and lifecycle. Slot resources turn page regions into first-class response pipelines instead of informal partial includes.

## The problem

Traditional page composition often leaves nav, sidebar, widgets, and footers as special-case includes with hidden data dependencies. Frontend and backend fragments drift apart because each region gets wired by a different mechanism. When regions are not first-class resources, no one can tell where their data really comes from or how they are refreshed.

## How it works

Each region is a resource with its own handler flow, render context, asset collection, and optional deferred or live lifecycle. The same template system renders the page, the sidebar, the nav, and reactive slots.

`layout_slot()` composes the shell declaratively while the data flow stays explicit and reviewable.

## The rules

- A slot is a real response object, not a string include or a magical template fragment.
- Slots use the same Twig system and rendering model as the page itself, so frontend and backend stop speaking different dialects.
- Each region can evolve independently: own handler, own template, own assets, own deferred or reactive lifecycle.
- `layout_slot()` composes the shell declaratively while the data flow stays explicit and reviewable.

## Key mechanisms

- **`#[AsSlotResource]`** — registers a named page region as a first-class resource for one layout handle.
- **`SlotHandlerPipeline`** — runs the region through typed slot handlers before it renders.
- **Shared Twig** — the page template and the slot template use the same rendering engine and conventions.
