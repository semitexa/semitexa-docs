---
id: prompt/rendering
section: prompt
slug: rendering
title: Rendering & Self-Binding
summary: How PromptRenderer compiles a prompt with Twig, and how a self-binding prompt exposes its typed data to the template through getter dot-access.
order: 30
locale: en
status: canonical
keywords:
  - PromptRenderer
  - BoundPromptInterface
  - Twig
  - dot-access
relatedDocuments:
  - prompt/catalog
  - prompt/overrides
---
# Rendering & Self-Binding

`PromptRenderer` turns a catalog entry into finished text. Prompts are rendered with Twig configured for plain text (autoescape off, strict variables on, compiled once into a private owner-only cache), then run through a safe, structure-preserving normalization.

## How it works

A prompt binds its own data. Rather than the caller assembling a stringly-keyed variables array, a prompt implements `BoundPromptInterface`, carries typed data, and is passed straight to the renderer:

```php
$rendered = $renderer->render(
    (new OsPersonaPrompt())->withData($assistantName, $userName),
);
```

The bound object is exposed to its template under the `prompt` handle, and the template reads its typed data through getters via dot-access:

```twig
You are {{ prompt.assistantName }}.
{%- if prompt.userName -%} You are speaking with {{ prompt.userName }}.{%- endif -%}
```

`{{ prompt.assistantName }}` resolves to the class's `assistantName()` getter, so there is no variables map to keep in sync — add a getter, reference it, done. Templates also support conditionals, loops, and composition of other catalog entries with `{{ include('other.id') }}`. Passing a bound prompt with the tenant repository keeps rendering override-aware; passing it alone resolves the prompt's own template deterministically.

## Why this matters

Discipline over boilerplate: the prompt owns its variable names, and a missing or misspelled binding fails closed at render time instead of silently emitting a half-substituted string to the model. Rendering is centralized, so overrides, normalization, and composition apply uniformly no matter which subsystem asks for the prompt.
