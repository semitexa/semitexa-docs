---
id: events/sse
section: events
slug: sse
title: SSE Stream
summary: Real-time server push without WebSockets — connect once and receive real backend events over plain HTTP.
order: 50
locale: en
status: published
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

(The legacy `ui.grid.data` / `ui.grid.error` frames that carried the v1 `UiGridDataResponse` shape were removed in the Phase 6 sweep.)

## Why this matters

SSE is simpler than WebSockets for unidirectional server-to-client push: plain HTTP and native browser support via `EventSource`. Semitexa's `AsyncResourceSseServer` integrates with the event dispatcher so that async and queued listener completions can be streamed to the correct session without the client needing to poll.

## Security model

A long-lived SSE connection holds a Swoole coroutine, a file descriptor, and a response handle for as long as the client stays connected. Unbounded public SSE is a DoS vector — an attacker who can open N connections keeps N workers busy. Semitexa treats SSE as a privileged resource.

**Defaults:**

- **Authentication is required.** `/__semitexa_kiss` — the single SSE stream endpoint, used by Semitexa's browser-side SSE bootstrap — enforces an authenticated session. An unauthenticated request receives `401 Unauthorized`.
- **Per-IP connection cap.** `SSE_MAX_CONN_PER_IP` (default `5`) bounds concurrent streams from a single client. Exceeding the cap returns `429 Too Many Requests` with `Retry-After: 30`.
- **Per-worker global cap.** `SSE_MAX_CONN_GLOBAL` (default `500`) bounds total concurrent streams in one worker. Production deployments should size this against available FD and coroutine budget.
- **Hard connection age.** `SSE_MAX_CONNECTION_AGE_SECONDS` (default `600`) forces the server to send a `close` event and disconnect after ten minutes. Browser `EventSource` auto-reconnects; long-lived clients must handle the reconnect path.
- **Same-origin handshake.** The request must carry `Origin` or `Referer` and the host must match the server. Requests with neither header present are rejected with `403 Forbidden`.

**Opt-in anonymous streams.** For public-facing streams (dashboards, ticker widgets, etc.) set `SSE_PUBLIC_ANONYMOUS=true`. Anonymous requests are then allowed, but the connection caps above still apply unchanged.

## Client contract

The framework does not replay missed events on reconnect. When a client reconnects after a network drop or after the server hits the age cap, it receives a fresh stream from the server's current state. If your application needs at-least-once delivery semantics, queue events domain-side and re-emit them on reconnect based on the `Last-Event-ID` header or your own cursor.

## Environment variables

| Variable | Default | Purpose |
|---|---|---|
| `SSE_PUBLIC_ANONYMOUS` | `false` | Allow unauthenticated clients to open `/__semitexa_kiss`. |
| `SSE_MAX_CONN_PER_IP` | `5` | Concurrent SSE connections allowed from one IP per worker. |
| `SSE_MAX_CONN_GLOBAL` | `500` | Concurrent SSE connections allowed per worker. |
| `SSE_MAX_CONNECTION_AGE_SECONDS` | `600` | Seconds before the server force-closes the stream. `0` disables the cap. |
