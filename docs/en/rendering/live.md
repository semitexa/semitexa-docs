---
id: rendering/live
section: rendering
slug: live
title: Live by Default
summary: How a Semitexa page stays live — writes reach every open screen as keyed patches, components redraw themselves, state lives on the server, reconnects resume, clicks answer at once — and how to watch it happen.
order: 136
locale: en
status: canonical
verified_against: 2026.10.03.1952
keywords:
  - live
  - ui.collection.patch
  - UiIslandInterface
  - "#[UiState]"
  - ui-optimistic
  - Last-Event-ID
  - PageTimeline
---

# Live by Default

A page is live without publish code. A database write through the ORM publishes an invalidation;
every open feed and island that watches it re-runs, for its own visitor, in whichever worker
holds the page's stream. This page is the map of the pieces. Each links to its own page.

## One stream per page

A page has one KISS stream (`/__semitexa_kiss`). Everything the page sends goes through HUG
(`/__semitexa_hug`), and everything the server pushes comes back on KISS. There is no
per-feed connection and no WebSocket server. See [SSE Stream](../events/sse.md).

- **Resumable.** Every data frame carries an id. A reconnect names the last one it got and is
  given what it missed, or told to reset. The lifecycle frames (`connected`, `close`) carry no
  id, and deferred blocks keep their own numeric ids, which the replay ring does not hold.
- **Prompt.** A frame queued for a stream on the same worker is written at once. Deferred content
  measured 26 ms after DOMContentLoaded; it was about 380 ms while the stream loop slept out its
  tick.
- **As the visitor.** Every re-run, deferred region and island is rendered as the visitor of
  that page: re-resolved from its session each time, so a visitor who signs out stops receiving
  data.

## Lists: keyed patches

A grid subscribes to its feed through HUG and says it applies patches. When a write re-runs the
feed, the server sends only the rows that changed against the page it last sent
(`ui.collection.patch`: upsert, remove, order). The grid keeps every unchanged row's element, so
focus, a checked box and an open menu survive.

| Products, one row of ten edited elsewhere | Bytes on the wire |
|---|---|
| the whole page again | 1,565 |
| a keyed patch | 579 |

## Components: islands

A component that implements `UiIslandInterface::watches()` is drawn again when what it reads is
written, and morphed in place. `platform.dashboard` is one. See
[Components](components.md#islands-live-components).

## State on the server

`#[UiState]` properties are a component's state, kept in the shared store under a key only the
visitor's session can name. A handler changes them, and the component is saved and drawn again.
See [Components](components.md#state-on-the-server).

## Deferred, concurrently

Deferred components render in parallel, each in its own coroutine, and arrive as they finish.
Measured with two widgets that each take 0.4 s: about 0.4 s together, not 0.8. See
[Deferred Blocks](deferred.md).

## Answers at once

`ui-optimistic` draws the answer an action expects the moment it fires, and undoes it if the
server refuses. A grid's delete is optimistic by default. See
[UI Events](ui-events.md#optimistic-updates).

## Watching it

In development, every page keeps a timeline:

- which component sent which event, how long it took, and the effects that came back;
- every frame its stream wrote;
- each subscription and re-run, with what the re-run sent.

```bash
bin/semitexa ai:observe timeline --id=sse_…
```

The same timeline is at `/__observatory/timeline`. See [AI Tooling](../cli/ai-tooling.md#a-pages-live-timeline).

## Proof in the Playground

| What | Where | Checked by |
|---|---|---|
| keyed live grid, sign-out stops the feed | `/playground/products` | `live-keyed-grid.spec.ts` |
| optimistic bulk delete, refusal restores rows | `/playground/products` | `live-keyed-grid.spec.ts` |
| reconnect replay and reset | any live page | `live-replay.spec.ts` |
| dashboard island | `/playground` | `dashboard.spec.ts` |
| deferred card rendered as the visitor | `/playground/auth/whoami` | `playground-auth.spec.ts` |
| `#[UiState]` counter, optimistic +1 | `/ui-playground/components/counter` | `counter-component.spec.ts` |
