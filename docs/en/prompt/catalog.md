---
id: prompt/catalog
section: prompt
slug: catalog
title: Defining Prompts
summary: Declare a prompt with `#[AsPrompt]` and a standalone Twig body; the catalog discovers it like any other framework attribute.
order: 20
locale: en
status: canonical
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

A prompt is a thin PHP class carrying `#[AsPrompt]` plus a `.twig` file that holds the actual body. The class lives in the owning package's `Application/Prompt/` directory; the body lives in `resources/prompts/`.

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

The `id` is the catalog key. `channel` groups related prompts (for example `llm`, `os`, `search`). `template` is the package-relative path to the Twig body — self-documenting, so the class-to-file link is explicit rather than convention-only; when omitted it defaults to `resources/prompts/{id}.twig`. Discovery scans for the attribute, so adding a prompt is just adding a class and a file — no registry to edit.

## Why this matters

The body is plain text, edited as a real template file with proper tooling, while the class stays a passive definition. Keeping metadata in the attribute and text in the `.twig` file means the same prompt can be listed, rendered, overridden, and evaluated without any of those concerns leaking into the code that uses it.
