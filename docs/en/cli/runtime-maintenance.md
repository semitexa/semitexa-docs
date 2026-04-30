---
id: cli/runtime-maintenance
section: cli
slug: runtime-maintenance
title: Runtime Maintenance
summary: Reload workers, clear stale cache, sync registries, lint architecture rules, and probe handler wiring without reaching for ad-hoc shell scripts.
order: 20
locale: en
status: canonical
keywords:
  - server:reload
  - cache:clear
  - registry:sync
  - lint:*
  - test:handler
---
# Runtime Maintenance

Strong CLI does not stop at code generation. It also gives operators and developers a disciplined way to refresh, validate, and diagnose a live Semitexa runtime.

## How it works

`server:reload` picks up code changes without a full container restart. `cache:clear` handles stale compiled Twig templates and other artifacts. `registry:sync` regenerates DI-oriented registry bindings. `lint:*` validates handler signatures and architectural invariants. `test:handler` probes instantiation and DI wiring for a specific handler class.

## Why this matters

Without explicit maintenance commands, teams fall back to ad-hoc shell scripts, full restarts, or trial-and-error debugging. A coherent maintenance surface keeps the runtime operable and reduces the cost of investigating unexpected behavior during development and production operations.
