---
id: rendering/ui-composition
section: rendering
slug: ui-composition
title: UI Composition: UiPart and UiSlot
summary: How a component declares the primitives it is made of, how part props resolve, and how bind, slots and inputProps behave.
order: 170
locale: en
status: canonical
keywords:
  - UiPart
  - UiSlot
  - AsComponent
  - bind
  - inputProps
---

# UI Composition: UiPart and UiSlot

## Composition (UiPart + UiSlot)

The composition slice on top of primitives. A class becomes a Platform UI component by combining SSR's `#[AsComponent]` with one or more `#[UiPart]` / `#[UiSlot]` attributes:

```php
use Semitexa\PlatformUi\Attribute\UiPart;
use Semitexa\PlatformUi\Attribute\UiSlot;
use Semitexa\PlatformUi\Application\Service\Primitive\Builtin\InputPrimitive;
use Semitexa\Ssr\Attribute\AsComponent;

#[AsComponent(name: 'platform.field', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiPart(name: 'input', uses: InputPrimitive::class)]
#[UiSlot(name: 'prefix')]
#[UiSlot(name: 'suffix')]
final class FieldComponent {}
```

- `#[UiPart(name, uses, defaults?)]` — `uses` is a **FQCN** of a class marked with `#[AsUiPrimitive]`; primitive aliases (`'input'`) are only accepted in Twig/demo surfaces. `defaults` is an optional prop map merged under caller props.
- `#[UiSlot(name, description?)]` — declares a caller-content hole. Slot values are passed as the third argument of the SSR `component()` Twig helper.

Both attributes are `IS_REPEATABLE`. Component rendering still flows through SSR's `ComponentRegistry::initialize()` + `ComponentRenderer::render($name, $props, $slots)` — the Platform UI side adds only the composition metadata, exposed through `UiComponentRegistry::get($name)` for introspection and tests.

## `FieldComponent` example

```twig
{{ component('platform.field', {
    label: 'Email address',
    name: 'email',
    type: 'email',
    placeholder: 'name@example.com',
    help: 'We use this for notifications.',
    required: true,
}) }}

{{ component('platform.field',
    { label: 'Search', name: 'q', placeholder: 'Search…' },
    { suffix: primitive('button', { text: 'Go', tone: 'brand', size: 'sm' }) }
) }}
```

Rendered output carries a stable root marker so future frontend runtimes can scan the DOM:

```html
<div data-ui-component="platform.field" ui-component="field" sx-layout="stack" sx-gap="1">
  <label for="email" ui-text="label">Email address <span aria-hidden="true">*</span></label>
  <input ui="input" data-ui-primitive="platform.input" type="email" name="email" id="email"
         placeholder="name@example.com" aria-describedby="email-help" required>
  <span id="email-help" ui-text="muted">We use this for notifications.</span>
</div>
```

`error` automatically sets `ui-state="invalid"`, `aria-invalid="true"`, and replaces the help line with a danger-toned error message bound through `aria-describedby`.

## Part prop resolution

Part props are resolved by `UiPartPropResolver` in a deterministic **four-step** order. Components declare a provider with `#[ProvidesUiPart(part: '…')]` on a public, non-static instance method returning `array`, and optionally a `bind` path on the part:

```php
use Semitexa\PlatformUi\Attribute\ProvidesUiPart;
use Semitexa\PlatformUi\Attribute\UiPart;
use Semitexa\Ssr\Attribute\AsComponent;

#[AsComponent(name: 'platform.field', template: '@platform-ui/components/runtime/field.html.twig')]
#[UiPart(
    name: 'input',
    uses: InputPrimitive::class,
    defaults: ['type' => 'text'],
    bind: 'value',
)]
final class FieldComponent
{
    /** @param array<string, mixed> $props
     *  @return array<string, mixed> */
    #[ProvidesUiPart(part: 'input')]
    public function inputPart(array $props): array
    {
        // Structural props only — `value` is owned by the bind step.
        $id = $props['id'] ?? $props['name'] ?? null;
        $hasError = isset($props['error']) && $props['error'] !== '';
        return [
            'name' => $props['name'] ?? null,
            'id' => $id,
            'type' => $props['type'] ?? 'text',
            'placeholder' => $props['placeholder'] ?? null,
            'state' => $hasError ? 'invalid' : ($props['state'] ?? null),
            'required' => (bool) ($props['required'] ?? false),
            'disabled' => (bool) ($props['disabled'] ?? false),
            'aria_invalid' => $hasError ? true : null,
            'aria_describedby' => $hasError && $id ? "{$id}-error"
                : (isset($props['help']) && $id ? "{$id}-help" : null),
        ];
    }
}
```

**Resolution order (later steps overwrite earlier keys):**

1. `#[UiPart(defaults: [...])]` — declarative baseline declared on the part itself.
2. `#[ProvidesUiPart]` provider method result — invoked with the caller component props.
3. `#[UiPart(bind: '<path>')]` — bind-derived **`value`** (value-only in this slice). Walks the dot-segmented path through the caller component props. Resolved non-null values land on `$resolved['value']`; null/missing values leave whatever the provider set.
4. Caller `inputProps` overrides — passed by the component template via `ui_part_props('input', inputProps|default({}))`.

**Provider contract (enforced at metadata extraction):**

- `part` must reference an existing `#[UiPart]` on the same class.
- Only one provider per part; duplicates fail at registration.
- Provider must be `public`, non-static, non-abstract.
- Provider must declare return type `array` (or omit the return type entirely; the resolver still enforces `is_array()` at call time).
- Providers must be pure in this slice — no IO, no service calls, no database access.

**`UiPartPropResolver` API:**

```php
$resolver->resolve(
    UiComponentMetadata $metadata,
    string $partName,
    array $componentProps,
    array $overrides = [],
    ?object $componentInstance = null,
): array
```

The optional `$componentInstance` lets callers (e.g. an enhanced renderer) inject a container-built component instance. When omitted, the resolver instantiates the component class via reflection (works for any no-required-arg constructor — currently every Platform UI component).

**Twig helpers:**

Two helpers cover both rendering styles. Prefer **`ui_part()`** for new component templates — it renders + marks the part atomically:

```twig
{# preferred: one-shot render with explicit data-ui-part marker #}
{{ ui_part('input', inputProps|default({})) }}
```

`ui_part(partName, overrides = [])` resolves props through `UiPartPropResolver`, renders the underlying primitive via `PrimitiveRenderer`, and **injects `data-ui-part="<partName>"` as the first attribute on the rendered root tag** so the frontend runtime can resolve parts by **UiPart name** instead of conflating with the primitive's `ui` alias. Returns a `Markup`.

```twig
{# alternative: explicit prop map (legacy, still supported) #}
{%- set _input_props = ui_part_props('input', inputProps|default({})) -%}
{{ primitive('input', _input_props) }}
```

`ui_part_props()` returns just the resolved prop map (an array, not Markup) so callers can split resolution from rendering — useful when the same prop map needs to be inspected or passed through additional logic. Templates that use this path do **not** get the `data-ui-part` marker automatically; the frontend runtime falls back to matching `[ui="<part-name>"]` for them.

Both helpers read the current `_component.name` from the Twig context, look up the metadata in `UiComponentRegistry`, extract component props (every context key not prefixed with `_`), and call `UiPartPropResolver::resolve()`.

## Bind / value model

`#[UiPart(bind: '<path>')]` declares which **value path** inside the caller component props supplies the part's `value` prop. Bind is server-rendered projection only — no live updates, no event wiring.

**Value path syntax** (validated by `UiValuePath::parse()` at metadata extraction time):

```
^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$
```

- Each segment starts with a letter or underscore, then letters / digits / underscores.
- Segments are separated by exactly one dot.
- No empty segments, no leading/trailing dots, no double dots, no brackets, no wildcards, no spaces, no Twig delimiters, no PHP syntax.

| valid | invalid |
|---|---|
| `value` | `""` |
| `email` | `.value` |
| `user.email` | `value.` |
| `address.street` | `user..email` |
| `filters.search_text` | `user[email]` |
| `_private` | `user.*` |
| `user1.email2` | `user email` |
|  | `{{ value }}` |
|  | `1user`, `user.1email` |
|  | `$value` |
|  | `user-email` |

**Bind semantics in this slice:**

- Bind is **value-only** — only the `value` key of the resolved part-props map is touched. Future revisions may extend this to `checked` / `selected`.
- A bind path that resolves to `null` (missing segment / explicit-null entry / non-array intermediate) **does not clobber** the provider-supplied value. This makes bind safe to layer on top of a provider that already supplies a fallback.
- Nested access is supported. `bind: 'user.email'` walks `$props['user']['email']` and returns `null` if any segment is missing or if any intermediate value is not an array.
- The provider should typically **not** project `value` itself when the part is bound — bind owns the value key. Provider-supplied values still survive when bind resolves to null, useful for "show provider fallback when component has no value".

**FieldComponent bind example:**

```twig
{{ component('platform.field', {
    label: 'Email',
    name: 'email',
    value: 'hello@example.com',
}) }}
{# rendered: <input … name="email" id="email" type="text" value="hello@example.com"> #}

{{ component('platform.field', {
    label: 'Email',
    name: 'email',
    value: 'hello@example.com',
    inputProps: { value: 'override@example.com' },
}) }}
{# rendered: <input … name="email" value="override@example.com">  ← caller overrides win #}

{{ component('platform.field', { label: 'Email', name: 'email' }) }}
{# rendered: <input … name="email" id="email" type="text">  ← no value attribute when bind yields null #}
```

`inputProps.value` always wins because caller overrides are step 4. `inputProps` can also introduce any key the target primitive template emits (see the "inputProps behaviour" section below).

## Slots

Slots are caller-provided content holes. Pass them as the third argument of `component()`:

```twig
{{ component('platform.field',
    { label: 'URL', name: 'url' },
    { prefix: 'https://', suffix: primitive('button', { text: 'Save' }) }
) }}
```

The component template reads slots via SSR's `slot('prefix')` Twig function. Missing slots render nothing.

## `inputProps` behaviour on `FieldComponent`

`inputProps` is the caller-supplied explicit-override map merged onto the resolved input primitive props **after** the provider runs. Two important guarantees:

- Universal merge at the resolver: `inputProps` keys win over both `#[UiPart(defaults: …)]` and the provider's output.
- Display fidelity is bounded by what the target primitive template emits. The input primitive emits a fixed attribute set (`name`, `id`, `type`, `value`, `placeholder`, `size`, `state`, `required`, `disabled`, `aria_invalid`, `aria_describedby`). `inputProps` keys outside that set still land in the resolved map but won't appear in HTML unless the primitive template extends its emission rules.
