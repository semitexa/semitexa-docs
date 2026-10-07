---
id: rendering/page-blocks
section: rendering
slug: page-blocks
title: Page Blocks
summary: Landing-page sections as data-driven components — hero, features, stats, pricing, call to action, FAQ on native details[name], footer — laid out by their own width, with every link target checked by ui_href().
order: 125
locale: en
status: canonical
verified_against: 2026.10.03.1952
keywords:
  - platform.block-hero
  - page blocks
  - landing page
  - ui_href
  - details name
---

# Page Blocks

A page block is one section of a marketing or product page, rendered from props alone. A page
builder, a CMS or a plain Twig template hands over data. The block owns its markup, its
semantics and its CSS (`platform-ui:css:blocks`, loaded the first time a block renders).

| Component | What it is |
| --- | --- |
| `platform.block-hero` | Eyebrow, headline (`h1`, or `h2` with `headingLevel: 2`), lead and actions; a `media` slot beside the text when the block is wide. |
| `platform.block-features` | A grid of features: icon, title (optionally a link) and a line each. |
| `platform.block-stats` | Headline numbers as a `<dl>`, so each value keeps its label for a screen reader. For a dashboard KPI use `platform.stat`. |
| `platform.block-pricing` | Plans side by side: price text, what is included and one action each. A `featured` plan gets the solid action and a ring. |
| `platform.block-cta` | A call to action on its own band (`tone: brand` or `neutral`). |
| `platform.block-faq` | Questions and answers on native `<details name>`: one open at a time, no JavaScript, opened by find-in-page. |
| `platform.block-footer` | Brand and tagline, columns of links (each a labelled `<nav>`), a legal row. |

Each block declares an `#[AsUiContract]` with typed props and worked examples, so
`bin/semitexa platform-ui:catalog --kind=component` lists them, and so does the Workbench once it
ships. The live demo is `/ui-playground/components/blocks` in the sandbox.

## Rendering blocks

```twig
{{ component('platform.block-hero', {
    eyebrow: 'New in 2026.10',
    title: 'Ship the interface, not the plumbing',
    lead: 'Server-rendered components and one transport for every interaction.',
    actions: [
        {label: 'Get started', href: '/start', icon: 'arrow-right'},
        {label: 'Read the docs', href: '/docs', variant: 'outline'},
    ],
}, {media: include('partials/hero-shot.html.twig')}) }}

{{ component('platform.block-faq', {
    items: [
        {question: 'Does it need JavaScript?', answer: "No.\n\nA blank line starts a new paragraph."},
        {question: 'Can two be open?', answer: 'Only with exclusive: false.'},
    ],
    open: 1,
}) }}
```

An action is `{label, href, variant?, icon?}`, where `variant` is a button variant (`solid`,
`soft`, `outline`, `ghost` or `link`). A link is `{label, href}`. Prices, numbers and dates are
text the project formats: the blocks do no maths and no locale formatting.

There is no `block()` Twig function, because Twig already owns that name for template blocks.
Blocks are components, so they share `component()`, the catalog and the Workbench with everything else.

## Layout by the block's own width

Every block is an inline-size container (`container: block / inline-size`) and lays itself out
with `@container`, not viewport media queries. In practice:

- the hero puts its media beside the text from 56rem of **block** width;
- the call to action puts its actions beside the title from 52rem;
- the features, stats and pricing grids fill the width they get (`auto-fill` / `auto-fit`).

A block in a 22rem sidebar column of a wide window therefore stacks exactly as it does on a phone.

## Link targets are checked: `ui_href()`

Block props are data, and data can carry `javascript:alert(1)`. Escaping stops an attribute
break-out, but a perfectly escaped `javascript:` href still runs. Every href a block renders
therefore goes through `ui_href()`, which:

- keeps a path, a relative reference, a fragment, a query, and `http:`, `https:`, `mailto:` and
  `tel:` targets;
- returns `''` for anything else: another scheme, a protocol-relative `//host`, control
  characters.

On `''`, the blocks drop the action or link and keep the text: a feature title becomes plain text.
The `button` primitive passes its `href` through the same check, and a refused href renders a
`<button>` instead of a link. Use `ui_href()` in your own templates wherever an href comes from data:

```twig
{% set href = ui_href(item.url) %}
{% if href %}<a href="{{ href }}">{{ item.label }}</a>{% else %}{{ item.label }}{% endif %}
```

## FAQ groups

The FAQ's `<details>` share `name="<group>"` (default `faq`); the browser then closes the open one
when another opens. Two FAQs on one page need different `group`s, or they close each other.
`exclusive: false` drops the name, so answers open independently.

## The footer landmark

`platform.block-footer` renders a `<footer>`. It is the page's `contentinfo` landmark only outside
`<main>`, `<article>` and `<section>`, so render it after the layout's main, not inside it.
