---
id: di/factory
section: di
slug: factory
title: Factory Injection
summary: On-demand creation stays explicit — lazy instances without falling back to service locator habits.
order: 40
locale: en
status: canonical
keywords:
  - "#[InjectAsFactory]"
  - closed-world selection
  - on-demand
  - lazy instantiation
---
# Factory Injection

Factory injections expose a validated selection point for fresh instances without reopening the container model.

## How it works

The factory closure is injected by the container at boot. Each call to the closure returns a new instance, but the creation path remains explicit and reviewable.

## Why this matters

This keeps on-demand creation inside the DI contract instead of falling back to ad-hoc container access or service locator patterns.
