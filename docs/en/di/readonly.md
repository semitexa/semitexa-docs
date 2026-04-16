---
id: di/readonly
section: di
slug: readonly
title: Readonly Injection
summary: One explicit DI path, one shared worker instance — fast at runtime and stable under reload.
order: 20
locale: en
status: canonical
keywords:
  - "#[InjectAsReadonly]"
  - worker-scoped
  - single-path DI
  - reload-stable
---
# Readonly Injection

Readonly injections are worker-scoped services resolved once and reused across all executions within the same Swoole worker.

## How it works

The container builds the instance during boot, injects it into the marked property, and never clones or replaces it for the lifetime of the worker process.

## Why this matters

Shared worker services avoid repeated allocation and provide stable object identity across requests, which matters for long-running processes.
