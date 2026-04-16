---
id: project-graph/overview
section: project-graph
slug: overview
title: Project Graph Overview
summary: Build a live structural map of the Semitexa codebase so engineers and AI agents can start from the real architecture, not from blind searching.
order: 10
locale: en
status: canonical
keywords:
  - ai:review-graph:generate
  - ai:review-graph:stats
  - ai:review-graph:capabilities
  - incremental graph
demo_preview: get-started-playbook
related_documents:
  - project-graph/inspection
  - project-graph/impact
---
# Project Graph Overview

Project Graph turns the current Semitexa repository into a queryable structural map.

## Canonical quick start

1. Generate or refresh the graph.
2. Verify that the graph is healthy enough to trust.
3. Inspect the project capability surface before deep edits or AI-assisted work.

## Commands

```bash
bin/semitexa ai:review-graph:generate --json
bin/semitexa ai:review-graph:stats --json
bin/semitexa ai:review-graph:capabilities --markdown
```

## Why this matters

This removes repeated repository rediscovery. Engineers and AI can begin with a real architectural artifact instead of assembling the system shape from ad hoc file reads.
