---
id: prompt/cli
section: prompt
slug: cli
title: Prompt CLI
summary: Inspect, render, override, and evaluate catalog prompts from the console.
order: 50
locale: en
status: canonical
keywords:
  - prompt:list
  - prompt:show
  - prompt:render
  - prompt:override
relatedDocuments:
  - prompt/overview
  - prompt/overrides
---
# Prompt CLI

The package ships console commands to inspect and operate the catalog. Each supports `--json` for machine-readable output.

## How it works

```bash
# List the catalog (optionally filtered by channel)
bin/semitexa prompt:list --channel=llm

# Show one prompt: metadata, raw template, variables, partials
bin/semitexa prompt:show --id=os.persona

# Render the effective prompt (tenant override -> catalog) exactly as the
# model would receive it; --var supplies the getter values by name
bin/semitexa prompt:render --id=os.persona --var=assistantName=Semi --var=userName=Taras

# Manage per-tenant overrides
bin/semitexa prompt:override set --id=os.persona --system="..."
bin/semitexa prompt:override list
bin/semitexa prompt:override history --id=os.persona
bin/semitexa prompt:override revert --id=os.persona --rev=2
bin/semitexa prompt:override remove --id=os.persona
```

`prompt:render` shows the resolved text with overrides applied, so it is the fastest way to confirm what a prompt actually expands to. `prompt:eval` (from `semitexa/llm`) goes one step further and sends the rendered prompt to the live LLM, optionally comparing the tenant override against the catalog default.

## Why this matters

Prompts are debuggable like any other addressable resource. You can list what exists, see exactly how a prompt renders for a given tenant, and manage overrides — all without reading source or redeploying.
