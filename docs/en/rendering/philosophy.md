---
id: rendering/philosophy
section: rendering
slug: philosophy
title: SSR Philosophy
summary: Semitexa SSR is one continuous rendering architecture: page, slots, deferred regions, live refresh, and interactive components stay inside one server-owned story.
order: 10
locale: en
status: published
keywords:
  - one rendering story
  - HtmlResponse
  - Presentation boundary
  - Deferred SSR
  - Framework-free enhancement
---
# SSR Philosophy

Semitexa SSR is not "render once on the server and then improvise". It is a coherent rendering system that refuses both kinds of drift: backend HTML plus frontend survival code, and fake "data vs presentation" separation where templates quietly become data loaders.

## The problem

"SSR" usually means the first paint is server-rendered, but the moment the page becomes dynamic, teams quietly switch to a second frontend architecture. At the same time, templates start accumulating query logic, ad hoc reshaping, and service calls because nobody protected the presentation boundary structurally.

Page regions become partials with hidden context, live widgets become little apps, and interaction drifts into custom fetch glue. Soon the page is "SSR" only in name, while React, Alpine, or bespoke client glue becomes the real owner of interaction and state interpretation.

## The seven pillars

- **Page Contract** — Resource DTOs make the page response explicit before Twig sees a single field.
- **Presentation Boundary** — Templates consume prepared data instead of querying storage, calling APIs, or inventing new view-side mapping rules.
- **Region Contract** — Slots are real resources with their own pipeline, not fragment glue.
- **Late HTML** — Deferred blocks stream SSR HTML later instead of handing control to a client renderer.
- **Live HTML** — Reactive slots refresh from server truth without inventing a second state machine.
- **Interactive HTML** — Components can now dispatch backend events while staying in the SSR component model.
- **Framework-Free JS** — When JavaScript is needed, it stays as small component-owned enhancement rather than a mandatory UI framework layer.

## The rules

1. The page response must be explicit before template rendering begins.
2. Templates are presentation surfaces, not a place to fetch from databases, hit APIs, or smuggle handler logic into Twig.
3. A region is a resource with a pipeline, not a partial with lucky context.
4. Deferred does not mean "replace SSR with client rendering later". It means late server HTML.
5. Reactive does not mean "invent a mini SPA for one widget". It means the server keeps re-rendering truth.
6. Interactivity does not require escaping into bespoke API glue. Components can declare backend event contracts directly.
7. JavaScript may enhance the page, but Semitexa refuses to require React, Alpine, Angular, or another client framework as the second rendering layer.
8. Assets, scripts, and SEO stay inside the same rendering system instead of becoming a parallel deployment concern.

## What Semitexa SSR refuses to become

| Anti-pattern | Semitexa answer |
|---|---|
| "SSR for the first paint, client app for everything real." | Semitexa keeps deferred and reactive regions inside the same HTML pipeline. |
| "The template can just query what it needs." | Semitexa treats that as presentation-boundary failure. Handlers and resource DTOs must shape data before Twig sees it. |
| "This region is just a partial, pass whatever data it seems to need." | Semitexa promotes regions to slot resources with an explicit render contract. |
| "The widget is live, so we need a second state architecture." | Semitexa refreshes the slot from server truth and swaps HTML in place. |
| "Once the page becomes interactive, we obviously need React or Alpine." | Semitexa rejects that as a default assumption. Small component-owned scripts are enough when the server still owns rendering truth. |
