---
id: rendering/ui-workbench
section: rendering
slug: ui-workbench
title: UI Workbench
summary: A dev page that renders every primitive, component and behavior from the examples its contract declares, with props, slots, copyable Twig, skins and dark mode — and a browser suite that audits all of it.
order: 175
locale: en
status: canonical
verified_against: 2026.10.01.1020
keywords:
  - AsUiContract
  - UiExample
  - workbench
  - catalog
  - showcase
---

# UI Workbench

`GET /__ui/workbench` lists the whole UI catalog — primitives, components and behaviors — and renders each entry's worked examples through the real runtime: `primitive()`, `component()`, or the example's own Twig file. Nothing on the page is written per component. An entry appears as soon as its class carries an `#[AsUiContract]` with `examples:`.

Each entry page shows:

- every example on a stage, rendered by the same code an application uses;
- the Twig that reproduces it (`{{ component('platform.card', {…}, {…}) }}`), ready to copy;
- the props table generated from the contract's schema (type, allowed values, default, description);
- slots and declared accessibility capabilities;
- the PHP class and source line that declare it.

The toolbar switches the stage between `light` and `dark` (`?mode=dark`) and previews it under any installed skin (`?skin=ocean`).

## Access

It is a development surface: it names classes and files and renders fixture data. It answers under `APP_ENV=dev`, or wherever `PLATFORM_UI_WORKBENCH=1` is set (a showcase deployment), and 404 otherwise. Class names and source paths are shown only under `APP_ENV=dev`. A request another site could read (a cross-site `fetch`) is refused even then; following a link to it is fine.

## Declaring examples

```php
#[AsUiContract(
    summary: 'A single metric: a label, its value, and optionally a change and a caption.',
    props: [
        new UiProp('label', required: true),
        new UiProp('value', required: true, description: 'The value as shown, formatted ("1,284", "€42k").'),
        new UiProp('delta', nullable: true, description: 'The change, as shown ("+12%").'),
        new UiProp('trend', default: 'flat', values: ['up', 'down', 'flat']),
        new UiProp('caption', nullable: true, description: 'One line of context ("vs last week").'),
    ],
    examples: [
        new UiExample('default', 'Revenue', ['label' => 'Revenue', 'value' => '€42,180', 'delta' => '+12%', 'trend' => 'up', 'caption' => 'vs last week']),
        new UiExample('down', 'Falling', ['label' => 'Churn', 'value' => '2.1%', 'delta' => '-0.4%', 'trend' => 'down', 'caption' => 'vs last month']),
    ],
    previewSafe: true,
)]
```

The same contract is what an [AI-composed screen](ai-ui.md) is checked against, and its first example is the one an agent is shown. A contract is open to agents unless it says `agent: false`; the entries that carry a contract only for their Workbench preview say so.

An example is validated against the props schema when the catalog is built: an unknown prop or a value outside `values:` fails the build, so an example can never teach something the component rejects. Slot values are literal text and are escaped on the stage.

A behavior is a contract on markup, so its example is a Twig file rather than props:

```php
new UiExample('menu', 'Action menu', ['pos' => 'bottom-end'], template: '@platform-ui/examples/dropdown.html.twig'),
```

The file receives `opts` (the example's props as a `key: value; …` option string), `props` and `uid` (a page-unique id prefix). It must be a namespaced package path; inline markup is not accepted.

## The browser suite

`packages/semitexa-platform-ui/tests/E2E/workbench.spec.ts` walks the catalog:

- every example renders, in light and dark, with no page errors;
- accessibility, checked in the page (the E2E runner ships no axe-core): every control has an accessible name, `aria-controls` / `aria-labelledby` / `aria-describedby` resolve, ids are unique, and visible text and field values meet WCAG 2 contrast (4.5:1, or 3:1 for large text) against the composited background. Colours are resolved to sRGB by painting them, so `color-mix(in oklab, …)` results are measured rather than skipped, and a colour that cannot be resolved is reported. Behaviors are audited closed and again after opening every toggle, menu, dialog, tooltip and toast;
- every example stage matches its screenshot baseline under the default skin, pixel for pixel (`maxDiffPixels: 0`; a looser ratio let a changed text colour on a badge through). Baselines live in `workbench.spec.ts-snapshots/`; refresh with `--update-snapshots` after an intended visual change;
- keyboard paths: the dropdown menu (arrows, End, typeahead, Escape returns focus), tabs (arrows, one visible panel), accordion (region and `aria-expanded`), and a toast raised inside an open modal.

The audit found three defects the PHP suites could not: dark-mode primary buttons at 4.28:1, an `input` primitive that had no way to receive an accessible name, and soft warning badges at 3.06:1.

Where the Workbench is closed the suite is reported as skipped, not passed.
