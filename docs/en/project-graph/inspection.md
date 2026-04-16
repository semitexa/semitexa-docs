---
id: project-graph/inspection
section: project-graph
slug: inspection
title: Inspecting the Graph
summary: Explore modules, dependencies, usages, and capabilities directly from the graph instead of piecing them together from file-by-file searches.
order: 20
locale: en
status: canonical
keywords:
  - ai:review-graph:show
  - ai:review-graph:query
  - cross-module edges
  - capability manifest
demo_preview: get-started-playbook
related_documents:
  - project-graph/overview
  - project-graph/impact
---
# Inspecting the Graph

After generation, Project Graph becomes an exploration surface rather than a static artifact.

## Canonical flow

1. Render the graph in the format that matches the question.
2. Run focused structural queries instead of broad repository search.
3. Project capabilities when the consumer is an operator or an AI workflow.

## Commands

```bash
bin/semitexa ai:review-graph:show --format=markdown --module=Demo
bin/semitexa ai:review-graph:query --search=DemoCatalogService
bin/semitexa ai:review-graph:query --dependencies=Semitexa\\Demo\\Application\\Service\\DemoCatalogService
bin/semitexa ai:review-graph:capabilities --markdown
```

## Why this matters

Architectural questions become terminal queries rather than archaeology. That improves review speed, onboarding, and AI context quality.
