---
id: cli/ai-tooling
section: cli
slug: ai-tooling
title: AI Tooling Surface
summary: Semitexa exposes AI-facing commands as explicit CLI contracts: capabilities, skills, log access, and a local assistant entrypoint.
order: 50
locale: en
status: canonical
keywords:
  - ai:ask
  - dev:graph:capabilities
  - ai:skills
  - logs:app
  - ai
  - --json
---
# AI Tooling Surface

If the framework wants to be AI-native, the console surface has to be machine-readable and operationally safe, not just human-friendly.

## How it works

`ai:ask capabilities` (backed by `dev:graph:capabilities`) exports a manifest of generator and introspection commands with intended use, required inputs, and avoid-when guidance. `ai:skills` exposes AI-executable skills with risk level and confirmation policy. `logs:app` queries application logs in stable structured JSON. The `ai` entrypoint opens the local assistant backed by the registered skill manifest.

## Why this matters

The important part is not AI branding. The important part is that the framework exposes stable, machine-readable operational seams. Without them, agents either hallucinate capabilities or resort to brittle terminal scraping. Explicit manifests and JSON modes give AI operators an actual foundation to work from.
