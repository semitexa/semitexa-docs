---
id: llm/overview
section: llm
slug: overview
title: LLM Module Overview
summary: What `semitexa/llm` adds to the framework and how your project can expose its own CLI skills to the assistant.
order: 10
locale: en
status: canonical
keywords:
  - "#[AsAiSkill]"
  - custom skills
  - SkillManifest
  - policy-aware execution
relatedDocuments:
  - prompt/overview
---
# LLM Module Overview

The `semitexa/llm` package gives a Semitexa project a governed AI surface: your own console commands can become discoverable skills instead of living behind ad-hoc prompt instructions.

## How it works

You keep writing normal console commands. When one should be usable by the assistant, you add `#[AsAiSkill]` next to `#[AsCommand]`, choose the risk and argument policy, and let the manifest expose it to the LLM layer.

## Why this matters

This keeps AI integration inside the framework contract. Teams extend the system by adding real commands and explicit metadata, not by teaching a model private tribal knowledge about the project.
