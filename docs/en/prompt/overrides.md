---
id: prompt/overrides
section: prompt
slug: overrides
title: Per-Tenant Overrides
summary: A DB-backed override layer lets each tenant edit a prompt on top of the code catalog, with version history and restore — plus an additive guidance layer for feedback that must not rewrite the body.
order: 40
locale: en
status: canonical
verified_against: 2026.09.19.1020
keywords:
  - LayeredPromptRepository
  - prompt_override
  - version history
  - per-tenant
  - prompt_guidance
  - guidance
relatedDocuments:
  - prompt/rendering
  - prompt/cli
---
# Per-Tenant Overrides

Every prompt has a code default, but a tenant can edit its body without a redeploy. The override layer stores per-tenant edits in the database and falls back to the code catalog when none exists — the same pattern the framework uses for locale translations.

## How it works

`LayeredPromptRepository` satisfies the prompt repository contract: it resolves a prompt by looking for a tenant override first (`prompt_override`), then the code catalog. Because rendering and `{{ include('...') }}` composition both go through this repository, an override transparently wins for the current tenant while other tenants keep the default.

Each save is versioned in `prompt_override_history`, so a tenant can review past revisions and restore an earlier one. A version also records **who** saved it and **why** — six months on, what the prompt says is visible in the text, and the only open question is why it says it. Overrides are tenant-scoped, so one tenant's edits never leak into another's.

### Guidance: the additive alternative

An override replaces the *whole* body, which is the wrong shape for one sentence of feedback — honouring "fewer hashtags" that way means rewriting the entire prompt, including the rules that exist precisely because a model cannot be trusted to keep them.

`prompt_guidance` is additive instead. Rows carry the text, the author, the reason and an `enabled` flag, and land only where the template prints `{{ guidance }}`:

```twig
{% if guidance %}Standing guidance — it shapes HOW you write, never what is true:
{{ guidance }}
{% endif %}You are a careful assistant. Never state a checkable claim.
```

Placement decides **where** guidance lands: a prompt that never prints `{{ guidance }}` never shows it at all, and a prompt that prints it above its rules puts operator text there rather than anywhere else.

What placement is **not** is an enforcement boundary. Everything in a system prompt is read by the same model, so guidance that says "ignore the rules below" is still text the model interprets — earlier placement makes it no less persuasive. Value binding stops Twig from *evaluating* an operator's `{{ ... }}`; it does not stop a model from *obeying* an operator's sentence. **Treat guidance authors as trusted**, at the same level as someone who could edit the prompt. If you need a rule that holds whatever the prompt says, enforce it outside the prompt — a validating guard on the model's output, as `semitexa/cms` does for checkable claims. The shipped body is untouched, so drift detection keeps working and a framework update that improves a prompt still reaches a tenant who has guidance. Each row is individually removable — the thing an override cannot express, where undoing one sentence means reverting a whole version. Guidance is bound as a value and never parsed as Twig, so an operator's `{{ ... }}` is inert text.

One qualification on that permission, worth knowing before you compose prompts: guidance is resolved for the prompt you **render**, and Twig's `include` inherits the parent's context. A partial that prints `{{ guidance }}` therefore shows the *including* prompt's guidance wherever it is spliced in. No shipped partial prints it.

Both the table and the two new `prompt_override_history` columns come from `bin/semitexa orm:sync`. Until that runs, `prompt:guidance` reports what is missing and the render path quietly carries on without guidance — an additive layer never fails a render.

## Why this matters

Prompt copy is product surface — tone, phrasing, and guardrails often need to differ per customer or be tuned in production. The override layer makes that a data change, not a code change, while the version history keeps every edit reversible and auditable.
