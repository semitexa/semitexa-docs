---
id: llm/providers
section: llm
slug: providers
title: Providers & Backends
summary: Provider contracts, backend resolution, local vs remote Ollama, and the environment knobs that shape LLM runtime behavior.
order: 20
locale: en
status: canonical
keywords:
  - LlmProviderInterface
  - LlmProviderResolver
  - local Ollama
  - remote Ollama
---
# Providers & Backends

The provider layer isolates the assistant from any one backend. `semitexa/llm` talks to an `LlmProviderInterface`, while resolver and factory logic decide whether the active backend is local or remote.

## How it works

`LlmProviderResolver` asks a provider factory for the backend selected by `LlmBackend`. The built-in Ollama providers map Semitexa env config into health checks, chat requests, retry behavior, and response normalization.

## Why this matters

This keeps the assistant runtime swappable without changing the planner or executor. The command surface does not need to care which backend actually answered the model request.
