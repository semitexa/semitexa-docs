---
id: migration/one-component-model
section: migration
slug: one-component-model
title: One Component Model Migration Guide
summary: Upgrade to the release where every UI event goes through HUG and every live update through KISS — component events move to #[UiOn], the extra /__ doors are gone, and a few runtime defaults change.
order: 20
locale: en
status: canonical
verified_against: 2026.10.03.1952
keywords:
  - migration
  - "#[UiOn]"
  - "#[UiPart]"
  - component_event_attrs
  - ui_event_manifest
  - RelationState
  - SWOOLE_PACKAGE_MAX_LENGTH
  - lint:components
---
# One Component Model Migration Guide

This release finishes the move to two transport doors: **HUG** (`/__semitexa_hug`) carries everything the browser sends, and **KISS** (`/__semitexa_kiss`) carries everything the server pushes, over one stream per tab. Component events, feeds and collaborative documents now all ride on them.

The "Before" examples quote retired shapes for context only.

## 1. Component events — `#[UiOn]` instead of `event:` / `triggers:`

`#[AsComponent]` no longer takes `event:` or `triggers:`. The `component_event_attrs()` Twig function and `component-events.js` are removed. A component names the part the browser acts on (`#[UiPart]`). A method on the component handles the event (`#[UiOn]`) and answers with effects. If the domain should hear about it, the method dispatches the event.

Before:

```php
#[AsComponent(name: 'demo-disclosure-prompt', template: '…', event: DemoDisclosureExpanded::class, triggers: ['click'])]
final class DisclosurePromptComponent {}
```

```twig
<button {{ component_event_attrs('click', {targetId: target}) }}>{{ label }}</button>
```

After:

```php
#[AsComponent(name: 'demo-disclosure-prompt', template: '…')]
#[UiPart(name: 'trigger', uses: ButtonPrimitive::class)]
final class DisclosurePromptComponent
{
    #[UiOn(part: 'trigger', event: 'click')]
    public function onExpand(UiInteractionEvent $event): UiInteractionResult
    {
        return UiInteractionResult::ack()->dispatching(
            new DemoDisclosureExpanded(targetId: (string) ($event->props()['target'] ?? '')),
        );
    }
}
```

```twig
{%- set _ui_instance = ui_component_instance() -%}
<span data-ui-component="demo-disclosure-prompt" data-ui-component-instance-id="{{ _ui_instance }}">
  {{ ui_part('trigger', {text: label}) }}
  {{ ui_event_manifest(_ui_instance) }}
</span>
```

The props the method reads come from the signed context the page rendered, never from the browser. Existing `#[AsEventListener]`s for the domain event keep working unchanged.

## 2. The extra `/__` doors are gone

`/__semitexa_component_event`, `/__ui/event`, `/__ui/dispatch` and `/__ui/form-doc` no longer exist. Code that called them by URL must go through the runtime (`ui-core`). Feeds are now subscribed through HUG by route **name**, and their frames arrive on KISS. A feed route is GET-only and needs no `EventSource` of its own. `bin/semitexa lint:transport-doors` fails the build when a new `/__` route appears.

## 3. The page's KISS session meta is added for you

Live islands and live grids announce the page's KISS session into `<head>` once. Drop any hand-written `{{ ui_page_sse_session_meta() }}` from layouts. If a layout still prints one, the framework does not add a second.

## 4. Unknown component names fail loudly

`{{ component('…') }}` with a name nothing registered:

- with `APP_ENV=dev` it throws, naming the closest registered name;
- anywhere else it logs a `component_not_found` warning (with the suggestion) and renders an HTML comment.

Run `bin/semitexa lint:components` (part of `ai:verify`) to find them before a page is opened.

## 5. ORM

- **Owned relations** must be typed `array|RelationState`. A plain `array` is refused by the ORM metadata validator. Saving a model whose relation was never loaded used to delete that relation's rows.
- **DECIMAL** columns hydrate as exact strings (`"19.90"`). A property typed `float` still gets a float. A `string` property no longer loses the trailing zero or digits past ~15 significant ones.

## 6. Runtime defaults

- **Request size:** Swoole's `package_max_length` is `SWOOLE_PACKAGE_MAX_LENGTH`, 32 MiB by default (it was Swoole's 2 MiB, which silently dropped phone-photo uploads). The worker buffers a request up to this size in memory. Size it against your worker count and expected concurrent uploads.
- **Session regeneration:** `SessionInterface::regenerate()` changes the id immediately, and `save()` destroys the old one. Anything bound to the session later in the same request (signed UI contexts, UI state) now carries the id the browser presents next.
- **Handler redirects:** a `UiEventRedirectInstruction` to anything but a same-origin path (`/…`) is dropped with an error log. The rest of the answer still goes through.

## Checklist

1. Replace every `event:` / `triggers:` on `#[AsComponent]` with `#[UiPart]` + `#[UiOn]` (section 1).
2. `grep -r "component_event_attrs\|__ui/\|__semitexa_component_event"` in your templates and scripts must find nothing.
3. Remove `{{ ui_page_sse_session_meta() }}` from layouts.
4. Type owned ORM relations `array|RelationState`.
5. Set `SWOOLE_PACKAGE_MAX_LENGTH` if 32 MiB does not fit your workers.
6. Run `bin/semitexa lint:components` and `bin/semitexa lint:transport-doors`. `ai:verify` runs them for the files they cover.
