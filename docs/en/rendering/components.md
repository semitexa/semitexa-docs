---
id: rendering/components
section: rendering
slug: components
title: Components
summary: Reusable, attribute-registered UI components — discovered automatically from the classmap.
order: 40
locale: en
status: published
keywords:
  - "#[AsComponent]"
  - event
  - triggers
  - component_event_attrs()
  - EventDispatcherInterface
---
# Components

Attribute-registered SSR components with an explicit backend event contract, signed manifest, and one generic dispatch endpoint. Open the component class and you can now see both the rendered UI primitive and the backend event contract it is allowed to trigger.

## How it works

Components are discovered at boot from the classmap through `#[AsComponent]`. A component can declare which backend events it is allowed to trigger. The framework provides one generic dispatch endpoint and a signed manifest so the frontend knows what events are permitted.

When the component renders, `component_event_attrs()` outputs the data attributes needed for the client runtime to dispatch events through the bridge without custom fetch wiring per component.

## Key mechanisms

- **`#[AsComponent]`** — registers the class as a discoverable UI component.
- **`event` / `triggers`** — declare the backend event contract the component is allowed to trigger.
- **`component_event_attrs()`** — outputs data attributes in Twig for the client dispatch bridge.
- **`EventDispatcherInterface`** — handles incoming component events on the backend.

## Why this matters

Components can become interactive without requiring a separate API endpoint per component. The event contract is visible on the component class, the manifest is signed, and one shared bridge handles dispatch. Behavior stays co-located with the component rather than scattered across ad hoc endpoints and frontend fetch calls.
