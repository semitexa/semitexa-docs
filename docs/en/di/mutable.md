---
id: di/mutable
section: di
slug: mutable
title: Mutable Injection
summary: Execution-scoped services get a fresh clone every run — safe state without contaminating the worker.
order: 30
locale: en
status: canonical
keywords:
  - "#[InjectAsMutable]"
  - execution-scoped
  - clone
  - state isolation
---
# Mutable Injection

Execution-scoped services are cloned for each framework execution, preventing state leakage across HTTP requests, console runs, and async jobs.

## How it works

`Semitexa\Core` keeps a readonly tier for shared worker services and an execution-scoped tier for cloned prototypes. `#[InjectAsMutable]` marks dependencies that should be re-injected on the execution-scoped clone, while the owning service itself should opt into explicit execution scoping.

## Why this matters

Long-running workers make lifecycle bugs real. If stateful services accidentally become shared, the bug is cross-request contamination. Explicit execution scope keeps state boundaries reviewable and safe.
