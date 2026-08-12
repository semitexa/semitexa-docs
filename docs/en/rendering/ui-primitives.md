---
id: rendering/ui-primitives
section: rendering
slug: ui-primitives
title: UI Primitives
summary: The atomic ui="..." vocabulary -- button, input, label, field-shell, surface, badge -- and the attribute-driven runtime behind it.
order: 160
locale: en
status: canonical
keywords:
  - AsUiPrimitive
  - ui=
  - primitive
  - UiPrimitiveRegistry
---

# UI Primitives

Six primitives ship in v1. All are semantic HTML with CSS styling via `ui="<id>"` + modifiers. CSS is the stable contract; Twig macros in `resources/twig/primitives/` are optional DX.

## `ui="button"`

| Attribute | Values | Default |
|---|---|---|
| `ui-variant` | `solid` · `soft` · `ghost` | `solid` |
| `ui-tone` | `neutral` · `brand` · `success` · `warning` · `danger` | `brand` (solid) |
| `ui-size` | `sm` · `md` · `lg` | `md` |

States: `:hover`, `:active`, `:disabled` handled automatically.

```html
<button ui="button" ui-tone="danger">Delete</button>
<button ui="button" ui-variant="ghost">Cancel</button>
<a ui="button" href="/export" ui-variant="soft">Export</a>
```

## `ui="input"`

| Attribute | Values | Default |
|---|---|---|
| `ui-size` | `sm` · `md` · `lg` | `md` |
| `ui-state` | `default` · `invalid` | `default` |

Uses `color-mix(in oklab, ...)` for focus ring tinting against `--ui-accent-brand`.

## `ui="label"`

Form label with role-appropriate typography. `ui-size`: `sm`/`md`/`lg`.

## `ui="field-shell"`

Wraps label + input + optional `ui="error-text"`. When parent has `ui-state="invalid"`:
- Descendant `[ui="label"]` turns `--ui-state-danger`
- Descendant `[ui="input"]` border turns danger
- `[ui="error-text"]` becomes visible (hidden by default)

```html
<div ui="field-shell" ui-state="invalid">
    <label ui="label" for="email">Email</label>
    <input ui="input" id="email" type="email" ui-state="invalid">
    <span ui="error-text">Enter a valid email.</span>
</div>
```

## `ui="surface"`

Opinionated panel container. Defaults to panel background + subtle border + 1rem padding. Compose with `sx-padding`, `sx-radius`, `sx-surface` for variants.

## `ui="badge"`

| Attribute | Values | Default |
|---|---|---|
| `ui-variant` | `solid` · `soft` | `solid` |
| `ui-tone` | `neutral` · `brand` · `success` · `warning` · `danger` | `brand` |

## Deferred to v1.1+

`textarea`, `select`, `checkbox`, `radio`, `switch`, `hint`, `tag`, `divider`, `icon`, `toolbar`.

## Introspection

- `bin/semitexa platform-ui:css:explain button` — variants, tones, sizes, tokens referenced
- `Semitexa\PlatformUi\Primitive\PrimitiveRegistry::all()` — programmatic enumeration

## Runtime (attribute-driven)

`#[AsUiPrimitive]` declares a class as a Semitexa UI primitive. The lifecycle listener `BootPlatformUiRegistryListener` boots `UiPrimitiveRegistry` with `ClassDiscovery` at worker start; from that point primitives are discoverable by canonical name (e.g. `platform.button`) or short UI alias (e.g. `button`).

```php
use Semitexa\PlatformUi\Attribute\AsUiPrimitive;

#[AsUiPrimitive(
    name: 'platform.button',
    ui: 'button',
    template: '@platform-ui/primitives/runtime/button.html.twig',
    style: 'platform-ui:css:full',
)]
final class ButtonPrimitive {}
```

- `name` — canonical registry/debug identity. Used by handler resolution, signed contexts, manifests. Unique across the registry.
- `ui` — short CSS/markup alias for the `ui="..."` attribute. Unique across the registry. Derived from the last dot-segment of `name` when omitted.
- `template` — Twig template that renders the primitive. Receives `props` plus `_primitive: {name, ui}` in context.
- `style` / `script` — optional asset keys. Required through `AssetCollectorStore` at render time and deduplicated by the collector.

### `primitive()` Twig helper

`PlatformUiTwigExtension` registers `primitive(name, props)` via `#[AsTwigExtension]`:

```twig
{{ primitive('button', { text: 'Save', tone: 'brand', variant: 'solid' }) }}
{{ primitive('input', { name: 'email', placeholder: 'Email' }) }}
{{ primitive('badge', { text: 'Active', tone: 'success' }) }}
```

Both the canonical name and the ui alias resolve to the same primitive: `primitive('platform.button', ...)` ≡ `primitive('button', ...)`.

The rendered output carries stable root markers for future frontend-runtime scanning:

```html
<button ui="button" data-ui-primitive="platform.button" type="button" ui-tone="brand">Save</button>
```

### Primitive prop vocabulary

The current attribute-driven primitives accept this small vocabulary:

| primitive | accepted props |
|---|---|
| `button` | `text`, `tone` (`brand`/`neutral`/`success`/`warning`/`danger`), `variant` (`solid`/`soft`/`ghost`), `size` (`sm`/`md`/`lg`), `disabled`, `href`, `type` |
| `input`  | `name`, `id`, `type`, `value`, `placeholder`, `size`, `state` (`invalid`), `required`, `disabled`, `help`, `error` |
| `badge`  | `text`, `tone`, `variant` (`solid`/`soft`) |

Only `text` (and `href` on button) ever changes the rendered tag; everything else maps to a `ui-*` attribute that the active skin's `tokens.css` resolves. `error` on an input automatically sets `ui-state="invalid"`, `aria-invalid="true"`, and an inline danger-toned message; `help` renders muted help text with `aria-describedby`. Both wrap the input in a stack — bare inputs (no `help`/`error`) still emit a single `<input>` element so existing usage is preserved.

### Active skin/theme assumption

Platform-ui CSS reads every visual decision (color, radius, spacing, motion) from CSS custom properties prefixed `--ui-*`. Those properties are defined by the active **skin** at runtime (`/assets/skins/<slug>/tokens.css`). The active skin is determined by `semitexa/theme` from the (tenant, domain, locale) tuple. The project's `app` layout includes both `platform-ui:css:full` (auto-required via `requireGlobals()`) and the skin tokens link via `theme_skin_css()`, with tokens loaded **last** so they win the cascade.

### Local playground

`src/modules/UiPlayground` is the local development surface. Routes:

| Route | Demo |
|---|---|
| `/ui-playground` | Menu / dashboard |
| `/ui-playground/primitives/buttons` | Buttons — tones, variants, sizes, disabled, anchor |
| `/ui-playground/primitives/inputs` | Inputs — placeholder, sizes, help, error, disabled |
| `/ui-playground/primitives/badges` | Badges — tones, soft variant, in-context |
| `/ui-playground/foundation/colors` | Skin tokens — surfaces, accent/text, state, typography |

The playground only consumes public APIs — primitive declarations and the `primitive()` helper live in `semitexa/platform-ui`.

## Current limitations

- **SSE server-push channel is wired** (this slice). Patches can arrive via the dispatch response or pushed as canonical `ui.patch` frames over the single KISS stream (`/__semitexa_kiss`); both transports reuse the same `UiResponsePatch` shape and the same safe applier. SSE messages target DOM nodes that already exist inside the component instance — no node creation, no `innerHTML`, no arbitrary selectors.
- Patch op allow-list covers `setText`, `setValue`, `setAttribute` only. No `setHtml`, no class-list mutations, no node insertion/removal — those are future-slice concerns and intentionally absent.
- `setAttribute` is restricted to four allow-listed attribute names (`aria-invalid`, `aria-describedby`, `data-state`, `ui-state`). Anything else is rejected server-side and again by the frontend applier.
- A patch whose target element is missing in the rendered DOM (e.g. the caller did not pass `showServerAckTarget: true`) is a graceful no-op — the bridge emits `semitexa:ui-patch:failed` with `reason: "target_not_found"` and the rest of the batch continues.
- Dispatch responses are still **ack-style**: a single JSON body with optional `patches[]`. Streaming `/__ui/dispatch` responses (chunked patches over the dispatch transport) is not on the roadmap — clients that want streaming use the SSE channel.
- **Replay guard is wired through DI and runtime-checked**: `UiReplayStoreInterface` resolves to `CacheBackedUiReplayStore` by default via `SatisfiesServiceContract`, and the dispatcher's runtime guard refuses to invoke handlers in production-like environments when the bound store reports `isShared() === false`. The 503 `ui_replay_store_not_shared` response tells operators exactly what to fix. With `CACHE_DRIVER=redis` (this project's shipped config), replay protection is global across Swoole workers — verified live with 10/10 same-`(ctx,dispatchId)` requests returning 409.
- **Authorization hook is wired through DI**: `UiInteractionAuthorizerInterface` resolves to `AllowAllUiInteractionAuthorizer` by default via `SatisfiesServiceContract`. Apps override by registering their own implementation in a module that "extends" `semitexa-platform-ui`; the contract registry's module-order winner picks the descendant. No per-handler wiring required.
- No **full anti-abuse system**. Replay protection is exclusively `(ctx, dispatchId)` deduplication: a malicious client holding a valid `ctx` can mint as many fresh `dispatchId`s as it wants within the ctx TTL. Bot/abuse mitigation (rate limiting, captcha, behavioural heuristics) is a separate concern that should sit in pipeline middleware, not in the dispatcher.
- No **full policy / RBAC matrix**. The authorizer hook is a single yes/no decision per (component, instance, part, event). Richer policy expressions / role-aware scopes land in a later slice; the seam is intentionally narrow for now.
- No **replay nonce inside SignedContext**. Replay protection is exclusively `(ctx, dispatchId)` — the signed `ctx` is reusable within its TTL. Embedding a nonce in the signed context would force the server to mint a new ctx per dispatch, breaking opt-in transport bridging and complicating SSR.
- No **per-handler validation pipeline**. Handlers receive the raw (guard-scrubbed) payload; richer per-event payload schemas land later.
- No **DI-managed components yet**. Components must have a no-required-arg constructor. If they don't, the dispatcher returns 422 `cannot_instantiate_component` — by design, not a regression.
- The transport bridge is **opt-in per page**. The runtime never auto-attaches. This keeps non-event pages no-network and lets each surface decide its own dispatch contract.
- `SignedContext::sign` adds a TTL (default 300s). Captured events for an expired ctx will return 403 `invalid_signed_ctx`. Re-rendering the component reissues the ctx; no in-place re-sign API exists yet.
- Bind is **server-rendered projection only** — no client-side two-way binding, no live updates.
- Bind currently projects **`value` only**. `checked` / `selected` are not wired yet.
- `ProvidesUiPart` methods are pure projections; no IO, no service calls, no external data providers.
- `value` extraction on the wire body is intentionally minimal — `partEl.value` if present, then the `value` attribute. Composite values (e.g. `<select multiple>`, `<input type="file">`) are out of scope for this slice.
- Manifest entries currently target `value`-bound events only.
- The legacy `Semitexa\PlatformUi\Primitive\PrimitiveRegistry` coexists with the new attribute-driven registry; consumers should prefer the attribute-driven path.

### Future runtime steps (post-this-slice)

1. **Multi-provider custom rule contribution**: replace the full-registry-replacement model (this slice) with a contributor pattern so several modules can each add their own rules without coordinating on a single registry implementation. Rules continue to be sync + pure.
2. **Container-managed component instances**: replace `UiInteractionDispatcher::instantiate()`'s reflection-based `newInstance()` with a container-aware path so components can declare `#[InjectAsReadonly]` dependencies directly. Today components opt into the `UsesUiFieldRuleRegistry` bridge — once container-managed instantiation lands, the bridge can drop and rule-registry-aware components use property injection like every other Platform UI service.
3. **Multi-field / cross-field validation** — `FieldComponent`'s rule today sees only its own value. A future slice introduces a server-side validation context that can read related fields (e.g. password + confirm-password) without storing state.
4. **Richer patch ops**: `setClass` / `addClass` / `removeClass`, `setHidden`, conditional `setText` with templating, and a `redirect(...)` variant on `UiInteractionResult` — all under the same allow-list + instance-scoping invariants.
5. **DI-managed component instantiation**: container-aware resolution so handlers can declare typed dependencies in their component constructor.
6. **Atomic cache primitive** in `CacheManagerInterface` (SETNX / `add`) so `CacheBackedUiReplayStore::claim` can drop its get-then-put race window.
7. **SSE delivery semantics upgrade**: at-least-once via Redis pub/sub fanout (today the bridge uses LPOP — at-most-once per claim), plus reconnect with `Last-Event-ID` so a transient drop does not lose patches.
8. **SSE channel revocation**: an out-of-band revocation set (Redis key with token id) so an issued channel token can be invalidated before its TTL expires.
9. **Persistent component state** — a server-side projection a handler can read/mutate, with SSE deltas published over the canonical KISS stream. The validation result type from this slice is the smallest shape that fits — the persistent state layer will wrap it, not replace it. (Patch shape stays the same.)
10. **Framework-layer unification**: a `UiInteractionDispatcherInterface` contract so SSR's `/__ui/event` can delegate to platform-ui's dispatcher; the two endpoints collapse into one. (The streaming half of this unification is **done** — all UI streaming now rides the single `/__semitexa_kiss` stream on `AsyncResourceSseServer`.)
