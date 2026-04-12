# AI In Semitexa

Semitexa is designed to be understandable not only for humans, but also for AI agents working inside the codebase.

This is not a side benefit. It is part of the framework design.

## Start Here

- [AI Reference](../AI_REFERENCE.md)  
  Philosophy, intent, and the mental model for agents.

- [AI Best Practices](AI_BEST_PRACTICES.md)  
  The practical playbook: structure, patterns, and anti-patterns.

- [AI Entry Points](ai/)  
  Short task-oriented jump points.

- [Project Graph](../../../packages/semitexa-project-graph/docs/AI_INTEGRATION.md)  
  **Use this first.** The project graph gives AI agents a structural map of the codebase — modules, classes, dependencies, event flows, execution paths, and risk analysis. Reduces exploration time from hours to minutes.

## Core Promise

Semitexa tries to remove guesswork.

- explicit contracts over magic
- explicit structure over convention drift
- predictable module discovery
- typed payloads and resources
- clear runtime assumptions for Swoole

The goal is simple: an AI agent should be able to reason correctly about the codebase without inventing hidden framework behavior.

That same property makes the codebase better for humans too.
