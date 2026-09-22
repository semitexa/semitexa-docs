---
id: prompt/catalog
section: prompt
slug: catalog
title: Defining Prompts
summary: Declare a prompt with `#[AsPrompt]` and a standalone Twig body; the catalog discovers it like any other framework attribute.
order: 20
locale: en
status: canonical
verified_against: 2026.09.19.1020
keywords:
  - "#[AsPrompt]"
  - resources/prompts
  - Twig template
  - Application/Prompt
relatedDocuments:
  - prompt/overview
  - prompt/rendering
---
# Defining Prompts

A prompt is a thin PHP class carrying `#[AsPrompt]` plus a `.twig` file that holds the actual body. The class is runtime PHP, so it lives at `<owner-root>/src/Application/Prompt/`; the body is an asset, so it lives at `<owner-root>/resources/prompts/` — beside `src/`, not inside it. The owner root is a package (`packages/semitexa-cms/`) or an application module (`src/modules/Social/`).

## How it works

`#[AsPrompt]` records the catalog metadata:

```php
#[AsPrompt(
    id: 'core.identity',
    channel: 'partial',
    description: 'Reusable assistant-identity fragment.',
    template: 'resources/prompts/core.identity.twig',
)]
final class SemitexaIdentityPrompt implements BoundPromptInterface
{
    public const ID = 'core.identity';

    public function promptId(): string
    {
        return self::ID;
    }
}
```

The `id` is the catalog key. `channel` groups related prompts (for example `llm`, `os`, `search`). `template` is the path to the Twig body relative to the owning package **or application module** root — an application module needs no `composer.json` for this to resolve — self-documenting, so the class-to-file link is explicit rather than convention-only; when omitted it defaults to `resources/prompts/{id}.twig`. Discovery scans for the attribute, so adding a prompt is just adding a class and a file — no registry to edit. A class that declares `#[AsPrompt]`, ships no template under any owner root and implements no `PromptDefinitionInterface` has no body at all; that is raised as an error naming every root searched, rather than silently dropped from the catalog.

## Why this matters

The body is plain text, edited as a real template file with proper tooling, while the class stays a passive definition. Keeping metadata in the attribute and text in the `.twig` file means the same prompt can be listed, rendered, overridden, and evaluated without any of those concerns leaking into the code that uses it.
