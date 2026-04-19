---
id: project-graph/inspection
section: project-graph
slug: inspection
title: Inspecting the Graph
summary: Use Project Graph queries and intelligence views to inspect modules, dependencies, flows, events, and hotspots without reconstructing the repository manually.
order: 20
locale: en
status: canonical
keywords:
  - ai:review-graph:show
  - ai:review-graph:query
  - ai:review-graph:module
  - ai:review-graph:intelligence
demo_preview: get-started-playbook
related_documents:
  - project-graph/overview
  - project-graph/impact
---
# Inspecting the Graph

Once the graph exists, Project Graph becomes an explicit inspection surface rather than a one-off artifact.

## Canonical flow

1. Render the slice that matches the question.
2. Query dependencies, usages, or cross-module edges directly.
3. Reach for module and intelligence views when raw edges are not enough.

## Commands

```bash
bin/semitexa ai:review-graph:show --format=markdown --module=Demo
bin/semitexa ai:review-graph:query --search=DemoCatalogService
bin/semitexa ai:review-graph:query --dependencies=Semitexa\\Demo\\Application\\Service\\DemoCatalogService
bin/semitexa ai:review-graph:module Demo --include-events --include-flows --format=json
bin/semitexa ai:review-graph:intelligence --hotspots
```

## Why this matters

Architectural questions become focused graph-backed answers instead of archaeology. That improves onboarding, review speed, and the quality of AI context.
