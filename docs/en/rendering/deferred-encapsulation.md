---
id: rendering/deferred-encapsulation
section: rendering
slug: deferred-encapsulation
title: Block Isolation
summary: Two identical blocks on the same page run independently — scoped DOM, scoped JS, no conflicts.
order: 100
locale: en
status: published
keywords:
  - DOM scoping
  - data-instance
  - block isolation
  - independent timers
---
# Block Isolation

Two identical blocks on the same page run independently — scoped DOM, scoped JS, no conflicts. Repeated deferred blocks stay isolated through per-instance data attributes and scoped DOM.

## How it works

Each deferred block instance receives a unique `data-instance` attribute when it renders. Client-side behavior scopes its DOM queries to the component root instead of using global selectors. This means two instances of the same slot class can run on the same page with independent state, independent timers, and no interference between their DOM trees.

## Key mechanisms

- **DOM scoping** — each block instance owns its own DOM subtree, identified by `data-instance`.
- **`data-instance`** — unique per-render attribute that client scripts use as the scope root.
- **block isolation** — slot handler, template, and client module all treat each instance as independent.
- **independent timers** — two countdown or refresh loops running the same slot code do not share state.

## Why this matters

Without per-instance scoping, placing the same deferred widget twice on a page causes client scripts to interfere with each other — shared class names, shared DOM queries, and racing timers. Block isolation makes duplication safe by default, so the same slot can appear any number of times on one page without custom disambiguation code.
