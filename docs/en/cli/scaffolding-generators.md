---
id: cli/scaffolding-generators
section: cli
slug: scaffolding-generators
title: Scaffolding Generators
summary: Scaffold modules, pages, payloads, services, and contracts through commands that already understand Semitexa structure and AI-friendly output modes.
order: 30
locale: en
status: canonical
keywords:
  - make:module
  - make:page
  - make:payload
  - make:service
  - make:contract
  - --llm-hints
---
# Scaffolding Generators

The generator surface matters because it teaches the framework shape by producing the right files, not by asking the developer to remember ceremony.

## How it works

`make:module`, `make:page`, `make:payload`, `make:service`, and `make:contract` each encode Semitexa's expected file layout and attribute conventions directly into the generated stubs. Dry-run and `--llm-hints` modes let humans and agents inspect the plan before files are written. JSON output makes generator results consumable by automated workflows.

## Why this matters

The point is not fewer keystrokes. The point is fewer incorrect architectural starts. Good scaffolding shortens onboarding because the produced files demonstrate the intended structure directly and reduce wrong patterns before they become habits.
