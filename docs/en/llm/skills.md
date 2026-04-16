---
id: llm/skills
section: llm
slug: skills
title: Adding Skills
summary: How a console command becomes AI-executable through `#[AsAiSkill]`, metadata policy, and registry discovery.
order: 30
locale: en
status: canonical
keywords:
  - "#[AsAiSkill]"
  - "#[AsCommand]"
  - argumentPolicy
  - "env::AI_ENABLE_*"
---
# Adding Skills

A Semitexa AI skill is just a normal console command that also carries `#[AsAiSkill]` metadata. That second attribute is what makes the command discoverable and governable for the assistant.

## How it works

You add `#[AsAiSkill]` to a real command class, choose the confirmation and argument policy that matches the command, and optionally gate the skill through `.env` with `allowed: 'env::VAR::false'` when the command should not always be exposed.

## Why this matters

This keeps AI operations grounded in the same command system humans already use. Teams do not need a second hidden automation layer just for agents.
