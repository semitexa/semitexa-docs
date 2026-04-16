---
id: di/contracts
section: di
slug: contracts
title: Service Contracts
summary: Depend on contracts, but keep ownership explicit — deterministic substitution instead of runtime magic.
order: 50
locale: en
status: canonical
keywords:
  - "#[SatisfiesServiceContract]"
  - module-owned capability
  - closed-world factory
  - deterministic binding
---
# Service Contracts

A service contract is module-owned and explicit: one module declares the capability, and its implementations advertise themselves with `#[SatisfiesServiceContract]`.

## How it works

The container registry resolves contracts at boot from attributes, not string lookups. For keyed factories, Semitexa uses closed-world backed enums so the allowed variants are declared in code and validated exhaustively.

## Why this matters

This keeps substitution deterministic instead of magical. A reader can see who owns the capability, which implementations exist, and whether the selection space is complete without reverse-engineering runtime behavior.
