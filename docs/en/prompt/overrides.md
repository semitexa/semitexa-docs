---
id: prompt/overrides
section: prompt
slug: overrides
title: Per-Tenant Overrides
summary: A DB-backed override layer lets each tenant edit a prompt on top of the code catalog, with version history and restore.
order: 40
locale: en
status: canonical
keywords:
  - LayeredPromptRepository
  - prompt_override
  - version history
  - per-tenant
relatedDocuments:
  - prompt/rendering
  - prompt/cli
---
# Per-Tenant Overrides

Every prompt has a code default, but a tenant can edit its body without a redeploy. The override layer stores per-tenant edits in the database and falls back to the code catalog when none exists — the same pattern the framework uses for locale translations.

## How it works

`LayeredPromptRepository` satisfies the prompt repository contract: it resolves a prompt by looking for a tenant override first (`prompt_override`), then the code catalog. Because rendering and `{{ include('...') }}` composition both go through this repository, an override transparently wins for the current tenant while other tenants keep the default.

Each save is versioned in `prompt_override_history`, so a tenant can review past revisions and restore an earlier one. Overrides are tenant-scoped, so one tenant's edits never leak into another's.

## Why this matters

Prompt copy is product surface — tone, phrasing, and guardrails often need to differ per customer or be tuned in production. The override layer makes that a data change, not a code change, while the version history keeps every edit reversible and auditable.
