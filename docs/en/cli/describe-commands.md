---
id: cli/describe-commands
section: cli
slug: describe-commands
title: Project Describe Commands
summary: Routes, modules, contracts, and handlers can be described directly from the CLI instead of reverse-engineering the framework graph by hand.
order: 10
locale: en
status: canonical
keywords:
  - describe:route
  - describe:project
  - routes:list
  - contracts:list
  - semitexa:lint:*
---
# Project Describe Commands

A mature framework should explain itself under pressure. These commands turn route and container introspection into a first-class debugging surface.

## How it works

`describe:route` shows the full execution chain for one endpoint — payload, handlers, resource, template, and auth posture. `describe:project` and `routes:list` expose the module-level structure and all discovered request surfaces. `contracts:list` and `semitexa:lint:*` help validate DI bindings and architectural invariants before runtime incidents.

## Why this matters

The biggest gain is not convenience. It is shortening the distance between "something feels wrong" and "here is the exact part of the system that explains it." Both human engineers and AI operators benefit from a framework that can describe its own graph instead of requiring manual reconstruction.
