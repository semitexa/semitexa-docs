---
id: llm/execution-flow
section: llm
slug: execution-flow
title: Execution Flow
summary: How a user request becomes a planner decision, a reviewed skill proposal, and finally a real console execution.
order: 40
locale: en
status: canonical
keywords:
  - Planner
  - PlannerResponse
  - SkillExecutor
  - ConversationSession
---
# Execution Flow

The LLM execution path is intentionally staged: manifest, planner prompt, provider response, parsed decision, human confirmation when needed, and only then command execution.

## How it works

The assistant command builds a constrained system prompt from `SkillManifest`, sends the user request plus recent conversation history to the provider, parses the JSON decision into `PlannerResponse`, and passes approved proposals into `SkillExecutor`.

## Why this matters

This makes the path inspectable. When something goes wrong, you can tell whether the failure came from the provider, the planner decision, policy validation, or the underlying command itself.
