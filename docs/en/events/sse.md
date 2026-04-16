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
  - SseEndpointHandler
  - AsyncResourceSseServer
  - EventSource
  - text/event-stream
---
# SSE Stream

Real-time server push without WebSockets — a persistent HTTP connection that streams events. The client opens one `EventSource` connection and the server sends named events as they occur.

## How it works

The SSE endpoint holds an open `text/event-stream` response. The server pushes named event frames over this connection whenever backend activity produces output. The client JavaScript receives each frame and updates the page without polling or a WebSocket handshake.

## Why this matters

SSE is simpler than WebSockets for unidirectional server-to-client push: plain HTTP, automatic reconnect, and native browser support via `EventSource`. Semitexa's `SseEndpointHandler` integrates with the event dispatcher so that async and queued listener completions can be streamed to the correct session without the client needing to poll.
