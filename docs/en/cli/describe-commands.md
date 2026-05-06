---
id: cli/describe-commands
section: cli
slug: describe-commands
title: Project Graph Introspection
summary: Routes, modules, contracts, and handlers can be introspected directly from the CLI instead of reverse-engineering the framework graph by hand.
order: 10
locale: en
status: canonical
keywords:
  - ai:ask
  - dev:graph:route
  - dev:graph:project
  - dev:graph:module
  - dev:graph:event
  - routes:list
  - contracts:list
  - lint:*
---
# Project Graph Introspection

A mature framework should explain itself under pressure. These commands turn route and container introspection into a first-class debugging surface.

## How it works

`ai:ask route --path=/…` (backed by `dev:graph:route`) shows the full execution chain for one endpoint — payload, handlers, resource, template, and auth posture. `ai:ask project` and `routes:list` expose the module-level structure and all discovered request surfaces. `ai:ask module --name=…` drills into a single module. `contracts:list` and `lint:*` help validate DI bindings and architectural invariants before runtime incidents.

## Why this matters

The biggest gain is not convenience. It is shortening the distance between "something feels wrong" and "here is the exact part of the system that explains it." Both human engineers and AI operators benefit from a framework that can describe its own graph instead of requiring manual reconstruction.
