---
id: events/sse
section: events
slug: sse
title: Server-Sent Events (SSE)
summary: Live Server-Sent Events demo: one long-lived HTTP connection streams real backend events to the browser via EventSource. No WebSockets, plain PHP.
order: 50
locale: en
status: published
verified_against: 2026.09.19.1020
keywords:
  - AsyncResourceSseServer
  - EventSource
  - text/event-stream
  - SSE_PUBLIC_ANONYMOUS
  - SSE_MAX_CONN_PER_IP
  - SSE_MAX_CONN_GLOBAL
  - SSE_MAX_CONNECTION_AGE_SECONDS
---
# SSE Stream

Real-time server push without WebSockets — a persistent HTTP connection that streams events. The client opens one `EventSource` connection and the server sends named events as they occur.

## How it works

The SSE endpoint holds an open `text/event-stream` response. The server pushes named event frames over this connection whenever backend activity produces output. The client JavaScript receives each frame and updates the page without polling or a WebSocket handshake.

### Collection feed frames

A collection feed (`AbstractSseCollectionFeedHandler`) streams a fixed, canonical frame vocabulary — the event names are an allow-list, never a client-controlled string:

- **`ui.collection.data`** — carries the canonical `{ data, meta }` collection envelope, the same projection the JSON pull mode returns, so the client renders both transports from one code path. Emitted on initial connect, on rehydration, and on Track-R-driven re-runs.
- **`ui.collection.error`** — collection-level errors on the same stream.
- **`ui.collection.patch`** — a re-run sent as what changed:
  `{patch: {key, upsert: [rows], remove: [ids], order: [ids]}, meta?}`, against the page the
  subscription was last sent.
  - **Who gets it.** Only a subscriber that asked for patches (`"patches": true` on its HUG
    `subscribe`), and only from a feed that names its rows' key in `meta.key`. A CRUD screen
    names it; `platform.grid` asks.
  - **When a whole frame goes out instead.** On subscribe, on a view change, when a row has no
    key, and when the patch would not be smaller.
  - **When nothing goes out.** When nothing changed.
  - **On the page.** The grid keeps every unchanged row's element, so focus, a checked box and
    an open menu survive. A changed row is drawn afresh and marked `data-ui-row-updated`, with a
    brief accent bar at its start (not a tint behind the text, which lowered its contrast) unless
    reduced motion is preferred.
  - **Measured.** On Products, an edit to one row of ten is a 579-byte patch instead of a
    1,565-byte page.

(The legacy `ui.grid.data` / `ui.grid.error` frames that carried the v1 `UiGridDataResponse` shape were removed in the Phase 6 sweep.)

## Why this matters

SSE is simpler than WebSockets for unidirectional server-to-client push: plain HTTP and native browser support via `EventSource`. Semitexa's `AsyncResourceSseServer` integrates with the event dispatcher so that async and queued listener completions can be streamed to the correct session without the client needing to poll.

## Security model

A long-lived SSE connection holds a Swoole coroutine, a file descriptor, and a response handle for as long as the client stays connected. Unbounded public SSE is a DoS vector — an attacker who can open N connections keeps N workers busy. Semitexa treats SSE as a privileged resource.

**Defaults:**

- **Authentication is required, with two narrow exceptions.** `/__semitexa_kiss` — the single SSE stream endpoint, used by Semitexa's browser-side SSE bootstrap — enforces an authenticated session, and an unauthenticated request receives `401 Unauthorized`. A guest may still open a stream in two cases: the one-shot drain of a page's deferred blocks (a `deferred_request_id` without `mode=live`), and a bearer channel, whose `session_id` has the strict shape `sse_` followed by 32 lowercase hex digits (128 random bits; the server mints one for a page rendered without a session). `SSE_PUBLIC_ANONYMOUS=true` admits any anonymous persistent stream, except a demo stream, which always needs a session.
- **Per-IP connection cap.** `SSE_MAX_CONN_PER_IP` (default `5`) bounds concurrent streams from one client address in one worker. Exceeding the cap returns `429 Too Many Requests` with `Retry-After: 30`. The address is the one the rate limiter counts: the connecting peer or, when that peer is a trusted proxy (loopback, or listed in `TRUSTED_PROXIES`), the client it forwarded ([the client address behind a proxy](../routing/rate-limiting.md#the-client-address-behind-a-proxy)). Behind a proxy that is neither, every visitor shares one count, and a few idle streams turn everyone away.
- **Server-wide cap.** `SSE_MAX_CONN_GLOBAL` (default `500`) times `SWOOLE_WORKER_NUM` bounds the server's concurrent streams. They are counted across the workers in shared memory, because connections do not spread evenly over them. One worker may hold up to twice `SSE_MAX_CONN_GLOBAL`. A refusal returns `429 Too Many Requests` with `Retry-After: 30`, and its message says which cap it hit: "for this server" or "for this worker". The server-wide count is soft: workers admitting at the same moment can pass it by fewer streams than there are workers. Size it so that the server's file-descriptor limit covers `SSE_MAX_CONN_GLOBAL` × `SWOOLE_WORKER_NUM` streams, up to `SWOOLE_WORKER_NUM` − 1 more from the soft overshoot, and the descriptors the server holds for everything else, and so that one worker's coroutine budget covers twice `SSE_MAX_CONN_GLOBAL`. Without the shared counts (a console process, a test), the cap is `SSE_MAX_CONN_GLOBAL` per worker.
- **Hard connection age.** `SSE_MAX_CONNECTION_AGE_SECONDS` (default `600`) forces the server to send a `close` event and disconnect after ten minutes. Browser `EventSource` auto-reconnects; long-lived clients must handle the reconnect path.
- **Same-origin handshake.** The request must carry `Origin` or `Referer` and the host must match the server. Requests with neither header present are rejected with `403 Forbidden`.

**Opt-in anonymous streams.** For public-facing streams (dashboards, ticker widgets, etc.) set `SSE_PUBLIC_ANONYMOUS=true`. Anonymous requests are then allowed, but the connection caps above still apply unchanged.

### Who a re-run runs for

A feed re-runs whenever what it watches is written. Every re-run re-resolves its visitor from
the session of the request that subscribed:

1. the session is re-established;
2. the auth gate resolves who the visitor is from it;
3. the route's authorization runs again.

A visitor who has signed out (or lost a permission) gets no further rows: the re-run terminates
the subscription. Before 2026-10-06 a re-run resolved nobody, and a feed went on streaming to a
signed-out page. The bootstrapper was looked up as a container service, which it is not; it is
built through `AuthBootstrapperFactoryInterface`. The gate also ran before the session existed.

## Client contract

A page's KISS stream is resumable.

- **Ids.** Every data frame carries an `id:` (`k<connection>.<n>`). The lifecycle frames
  (`connected`, `close`) and frames that have their own id (the deferred blocks) do not get one.
- **The ring.** The server keeps the last 128 frames of each session for ten minutes. They are
  in Redis when there is a pool, so a reconnect that lands on another worker finds them;
  otherwise they are in the worker that wrote them.
- **Resuming.** A reconnect names the last frame it got. The browser sends `Last-Event-ID` by
  itself when it reconnects the same `EventSource`; the runtime adds `last_event_id` to the URL
  when it opens a new one (a revived tab, a reopened stream). The server writes every frame
  after that one, in order and under its first id, before anything else. The `connected` frame
  then reports `replayed: <n>`.
- **Reset.** When the named frame is no longer held, the server writes `ui.stream.reset` and
  `connected` reports `reset: true`. The runtime emits `semitexa:ui-sse:reset`.
- **Feeds.** Subscriptions belong to a connection. Every reconnect re-subscribes the page's
  feeds, which answer with a fresh snapshot, so a feed is current after any gap. Replay is what
  saves the rest: patches, toasts and component state written into a socket the browser had
  already lost.

This is what a frame written into a half-open connection, or lost on a network switch, used to
cost: the write succeeded, so nothing re-queued it. A frame whose write failed was, and still
is, re-queued for the session.

## Environment variables

| Variable | Default | Purpose |
|---|---|---|
| `SSE_PUBLIC_ANONYMOUS` | `false` | Allow unauthenticated clients to open `/__semitexa_kiss`. |
| `SSE_MAX_CONN_PER_IP` | `5` | Concurrent SSE connections allowed from one client address per worker. Behind a proxy that is not on loopback, set `TRUSTED_PROXIES`. |
| `SSE_MAX_CONN_GLOBAL` | `500` | Concurrent SSE connections per worker on average: the server holds up to this × `SWOOLE_WORKER_NUM`, one worker up to twice this. |
| `SSE_MAX_CONNECTION_AGE_SECONDS` | `600` | Seconds before the server force-closes the stream. `0` disables the cap. |
