---
id: events/pipeline
section: events
slug: pipeline
title: Request Pipeline Events
summary: The events every request passes through -- AuthCheck, AccessCheck, HandleRequest -- and the Swoole server lifecycle hooks around them.
order: 70
locale: en
status: canonical
keywords:
  - AsPipelineListener
  - AuthCheck
  - AccessCheck
  - HandleRequest
  - AsServerLifecycleListener
---

# Request Pipeline Events

## Pipeline Events (request lifecycle)

The request pipeline is a fixed sequence of phases: **Auth → Access → Handle**. Pipeline listeners are always **synchronous** — the HTTP response depends on their result.

| Phase | Event Class | Purpose |
|-------|------------|---------|
| Auth | `Pipeline\AuthCheck` | Runs auth handlers via `AuthBootstrapper`. Checks the payload's access attribute — `#[AsProtectedPayload]` or `#[AsServicePayload]`. |
| Access | `Pipeline\AccessCheck` | Reads `#[RequiresPermission]` / `#[RequiresCapability]` from the request DTO, calls `Gate::authorize()`. |
| Handle | `Pipeline\HandleRequest` | Runs route-specific handlers (PayloadHandler) and any registered pipeline listeners. |

Pipeline listeners use `#[AsPipelineListener(phase: AuthCheck::class, priority: 0)]` and implement `PipelineListenerInterface`.

Short-circuit via exceptions: `AuthenticationRequiredException` → 401, `AccessDeniedException` → 403.

## Server Lifecycle Hooks (Swoole server events)

Server lifecycle hooks are for Swoole server-managed events such as `PreStart`, `WorkerStart`, `WorkerStop`, `WorkerError`, `Start`, and `Shutdown`. They are not business events and they are not request pipeline listeners.

Use `#[AsServerLifecycleListener(phase: ..., priority: ...)]` on a class that implements the dedicated server lifecycle listener contract.

Typical use cases:

- package bootstrap during `WorkerStart`
- pre-fork shared resource creation during `PreStart`
- asset registry boot
- server/table wiring for Swoole-specific package infrastructure
- worker-local cache warmup
- cleanup or flush logic on `WorkerStop`
- diagnostics and telemetry on `WorkerError`
- server-level startup or shutdown integration in non-request code

Rules:

- do not put package-specific bootstrap logic directly into `SwooleBootstrap`
- use `PreStart` for resources that must exist before `Server::start()` forks workers
- do not use domain events for pre-container or worker bootstrap concerns
- do not use lifecycle hooks for request-scoped logic
- keep lifecycle listeners idempotent and boot-safe

Recommended placement:

- framework packages: `src/Server/Lifecycle/`
- project modules: `Application/Server/`
