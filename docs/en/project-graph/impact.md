---
id: project-graph/impact
section: project-graph
slug: impact
title: Impact, Context, and Watch Mode
summary: Use impact analysis, context packing, and watch mode to scope risky changes and keep graph-backed answers current during long work sessions.
order: 30
locale: en
status: canonical
keywords:
  - ai:review-graph:impact
  - --context
  - --prompt
  - ai:review-graph:watch
demo_preview: get-started-playbook
related_documents:
  - project-graph/overview
  - project-graph/inspection
---
# Impact, Context, and Watch Mode

Project Graph is not only for inspection. It is also a practical safety layer for change planning.

## Canonical flow

1. Analyze the impact radius before editing.
2. Package only the context needed for review or AI work.
3. Keep the graph current during active changes when the session is long.

## Commands

```bash
bin/semitexa ai:review-graph:impact Semitexa\\Demo\\Application\\Service\\DemoCatalogService
bin/semitexa ai:review-graph:impact Semitexa\\Demo\\Application\\Service\\DemoCatalogService --context
bin/semitexa ai:review-graph:impact Semitexa\\Demo\\Application\\Service\\DemoCatalogService --context --prompt=review
bin/semitexa ai:review-graph:watch --full-on-start
```

## Why this matters

This is where the graph becomes engineering safety: clearer blast-radius decisions, smaller prompts, fewer accidental side effects, and safer refactors.
