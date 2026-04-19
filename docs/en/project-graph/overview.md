---
id: project-graph/overview
section: project-graph
slug: overview
title: Project Graph Overview
summary: Understand what `semitexa-project-graph` adds: a stored structural map, an intelligence layer, and task-scoped context for large-codebase work.
order: 10
locale: en
status: canonical
keywords:
  - semitexa-project-graph
  - task-first workflow
  - intelligence layer
  - stored structural graph
demo_preview: get-started-playbook
related_documents:
  - project-graph/inspection
  - project-graph/impact
---
# Project Graph Overview

Project Graph is the package-level architecture memory for a Semitexa repository.

It persists structural facts about modules, handlers, services, events, flows, and dependencies so humans and AI can start from the actual system shape instead of rediscovering it task after task.

## Canonical workflow

1. Start from the task, not from a graph ritual.
2. Reach for graph-backed context when the task needs structural understanding.
3. Refresh the stored graph only when those answers are stale or missing.
4. Choose the narrowest graph command that answers the question.

## Commands

```bash
bin/semitexa ai:task "trace checkout architecture"
bin/semitexa ai:review-graph:context "trace checkout architecture" --format=json
bin/semitexa ai:review-graph:generate --json
bin/semitexa ai:review-graph:stats --json
bin/semitexa ai:review-graph:show --format=markdown --module=Demo
bin/semitexa ai:review-graph:intelligence --hotspots
```

## Why this matters

Project Graph is valuable because it turns architecture into a reusable artifact. Onboarding gets faster, structural review gets safer, impact analysis becomes easier, and AI prompts stop starting from random file sampling.
