---
id: prompt/overview
section: prompt
slug: overview
title: Prompt Catalog Overview
summary: What `semitexa/prompt` adds to the framework — an ORM-style catalog that turns inline prompt strings into addressable, versionable, override-aware records.
order: 10
locale: en
status: canonical
keywords:
  - "#[AsPrompt]"
  - prompt catalog
  - PromptTemplate
  - PromptRenderer
relatedDocuments:
  - prompt/catalog
  - prompt/rendering
  - llm/overview
---
# Prompt Catalog Overview

The `semitexa/prompt` package is an "ORM for prompts": instead of hiding LLM instructions in inline heredocs scattered across services, every prompt becomes an addressable catalog record — declared once, rendered through one seam, and overridable per tenant.

## How it works

A prompt is declared with a small `#[AsPrompt]` class and a standalone `.twig` body under the owning package's `resources/prompts/`. The class is discovered like any other framework attribute, its body is compiled by `PromptRenderer`, and callers resolve it by id (or by passing the typed prompt object itself). Every layer — listing, rendering, per-tenant DB overrides, evaluation — flows through the same catalog, so a prompt has exactly one home.

## Why this matters

Prompts stop being tribal knowledge buried in code. They are inspectable (`prompt:list` / `prompt:show`), rendered deterministically, versioned, and editable per tenant without redeploying. Framework subsystems such as `semitexa/llm` draw their system prompts from the same catalog, so the whole AI surface shares one governed source of truth.
