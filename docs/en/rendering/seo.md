---
id: rendering/seo
section: rendering
slug: seo
title: SEO
summary: Set title, description, and Open Graph tags from your handler — no template hacks needed.
order: 50
locale: en
status: published
keywords:
  - pageTitle()
  - seoTag()
  - Open Graph
  - description
  - structured data
---
# SEO

Handlers can set title, Open Graph, and canonical metadata directly without template overrides. SEO tags come from the handler, not from scattered template includes or a global layout config.

## How it works

The response resource exposes `pageTitle()` and `seoTag()` methods. Call them in the handler and the framework injects the correct `<title>`, `<meta>`, and Open Graph tags into the rendered document.

```php
return $resource
    ->pageTitle('SEO — Semitexa Demo')
    ->seoTag('description', 'Set title, description, and Open Graph tags…')
    ->seoTag('og:title', 'SEO — Semitexa Demo')
    ->seoTag('og:description', 'Set title, description, and Open Graph tags…')
    ->seoTag('og:type', 'website');
```

## Key mechanisms

- **`pageTitle()`** — sets the document `<title>` tag.
- **`seoTag()`** — sets any `<meta name>` or `<meta property>` tag by key and value.
- **Open Graph** — `og:title`, `og:description`, `og:type`, and any other OG property are declared the same way.
- **Structured data** — the same pattern extends to JSON-LD and other structured metadata formats.

## Why this matters

Template-level SEO hacks — overriding block variables, injecting strings through config, or writing per-page Twig blocks — make metadata a hidden concern that drifts away from the handler logic that owns the data. Keeping SEO declarations on the response resource means the full page contract is visible in one place.
