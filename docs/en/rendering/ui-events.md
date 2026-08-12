---
id: rendering/ui-events
section: rendering
slug: ui-events
title: UI Events and Transport
summary: Declaring events with UiOn, the signed render-time manifest, the capture-only frontend runtime, the HTTP dispatch endpoint and the SSE push channel.
order: 180
locale: en
status: canonical
keywords:
  - UiOn
  - event manifest
  - dispatch
  - response patch
  - SSE
---

# UI Events and Transport

## Events (`#[UiOn]`) — metadata only

`#[UiOn]` declares which component method is the **intended** handler for a given (part, event) pair. The attribute is **metadata only** in this slice: no DOM listener is wired, no HTTP transport endpoint is registered, no `UiInteractionDispatcher` exists, and the declared method is **not invoked at runtime**.

```php
use Semitexa\PlatformUi\Attribute\UiOn;
use Semitexa\PlatformUi\Attribute\UiPart;

#[UiPart(name: 'input', uses: InputPrimitive::class, bind: 'value')]
final class FieldComponent
{
    #[UiOn(part: 'input', event: 'change')]
    public function onInputChanged(array $event): void
    {
        // Metadata only — do not invoke yet.
    }
}
```

**Attribute shape**

```php
#[Attribute(Attribute::TARGET_METHOD)]
final class UiOn {
    public function __construct(
        public string $part,        // must reference a #[UiPart] on the same class
        public string $event,       // /^[a-z][a-z0-9:_-]*$/
        public ?string $updates = null,  // optional UiValuePath; inherits from part.bind when omitted
    ) {}
}
```

**Valid event-name examples:** `change`, `input`, `click`, `blur`, `focus`, `submit`, `value:change`.
**Rejected:** empty string, uppercase, spaces, `onclick()`, `{{ value }}`, brackets, quotes, digit-first.

**`updates` vs `bind` (strict-compatibility mode):**

| part.bind | updates argument | resolved `updatesPath` |
|---|---|---|
| `value`      | omitted        | `value` (inherited) |
| `value`      | `value`        | `value` |
| `value`      | `other.path`   | **error** — strict mismatch |
| `user.email` | omitted        | `user.email` (inherited) |
| (none)       | omitted        | `null` |
| (none)       | `value`        | `value` |

When the part declares `bind: '<path>'` and the handler declares `updates: '<other-path>'`, the factory rejects the registration. This keeps the model deterministic until a future slice introduces multi-path updates.

**Validation rules (enforced at metadata extraction):**

- `part` must match an existing `#[UiPart]` on the same component.
- `event` must match `/^[a-z][a-z0-9:_-]*$/`.
- `updates` must parse via `UiValuePath` when provided.
- `updates` must equal `part.bind` when both are present (strict mode).
- Each `(part, event)` pair is unique within one component.
- The handler method must be `public`, non-static, non-abstract.
- One `#[UiOn]` per method.

**Metadata model**

`UiOnMetadata` records `componentName`, `class`, `partName`, `eventName`, `updatesPath` (a `UiValuePath` or `null`), `methodName`. It carries NO transport URL, NO handler-id — those belong to a future runtime slice and must not leak into the DOM. Access via `UiComponentMetadata::event($partName, $eventName)`, `::eventsForPart($partName)`, or the `events` map.

**Twig helper for debug surfaces:** `ui_component_events('<component-name>')` returns a list of plain arrays (`{part, event, updates, method, runtime}`) for documentation panels. The helper is read-only and does NOT emit any DOM event-runtime attributes.

## Signed event manifest (render-time, inert)

Every Platform UI component render emits a per-instance **signed event manifest** as inert JSON. The manifest is built from the component's `#[UiOn]` metadata; each entry is signed with SSR's existing `SignedContext` substrate (`sc1.<base64url-claims>.<base64url-hmac>` over canonicalized JSON, HMAC-SHA256, TTL-bound). The signing secret is shared with the rest of the framework's signed-context substrate (`APP_SECRET`, with dev-mode fallback to a derivative of `APP_NAME|APP_HOST|APP_PORT`).

**What lands in the DOM:**

```html
<div data-ui-component="platform.field"
     data-ui-component-instance-id="uci_4f8a…"
     ui-component="field" sx-layout="stack" sx-gap="1">
  …input + label + help/error…
  <script type="application/json"
          data-ui-event-manifest="uci_4f8a…"
          data-ui-component="platform.field">
    {
      "v": 1,
      "c": "platform.field",
      "i": "uci_4f8a…",
      "events": [
        { "p": "input", "e": "change", "u": "value", "ctx": "sc1.<b64>.<hmac>" }
      ]
    }
  </script>
</div>
```

**What stays server-side:** the method name (`onInputChanged`), the class FQCN (`Semitexa\PlatformUi\Application\Component\Builtin\FieldComponent`), and any handler resolution id. The future dispatcher resolves the method via `UiComponentRegistry::get($c)->event($p, $e)->methodName` — clients can never coerce a different method.

**Signed-claim payload** (decoded `ctx`):

```
{
  c:   string,   // component canonical name
  i:   string,   // per-render instance id
  p:   string,   // part name
  e:   string,   // event name
  u:   string,   // updates path — present only when the part declares bind
  iat: int,      // issued-at (unix seconds, added by SignedContext)
  exp: int       // expires-at (iat + ttl, default ttl = 300s)
}
```

**Helpers**

- `ui_component_instance(): string` — returns a fresh `uci_<16hex>` per render. Stamp it once at the top of a component template and pass it into `ui_event_manifest()` and the root `data-ui-component-instance-id` attribute.
- `ui_event_manifest(instanceId, ttlSeconds = null): Markup` — emits the `<script type="application/json" data-ui-event-manifest="…">` block for the component currently rendering. Reads the component identity from Twig's `_component` context; throws if called outside a Platform UI component. Returns an empty `events: []` array when no `#[UiOn]` declarations exist.

**Render-time service**

`UiEventManifestBuilder::build(UiComponentMetadata $metadata, string $instanceId, ?int $ttlSeconds = null): UiEventManifest` — pure, stateless. Calls `SignedContext::sign()` once per `#[UiOn]`. Returns a `UiEventManifest` value object with `toJsonShape()` for serialization.

**Inert by construction**

- No `<script>` ever contains executable JavaScript — the type is `application/json`, browsers parse it as data.
- No `onclick` / `onchange` / `oninput` / `data-ui-handler` / `data-ui-event-url` attributes are emitted anywhere.
- No method name or class FQCN appears in the rendered HTML.

## Frontend event runtime (capture-only)

Shipped in this slice. The runtime is a tiny IIFE (`packages/semitexa-platform-ui/src/Application/Static/js/event-runtime.js`) loaded globally via the asset manifest with `defer`. It scans the DOM for `<script type="application/json" data-ui-event-manifest>` blocks emitted by the server, attaches one document-level capture-phase delegated listener per distinct native event name across all manifests, and **captures matches locally**. It does not send anything anywhere.

**What the runtime does on every captured event:**

1. Walks up from `event.target` to the nearest `[data-ui-component-instance-id]` root.
2. Looks up the manifest for that instance id.
3. Iterates manifest entries; for each entry whose `e` matches the native event name, finds the part element. Lookup order: `[data-ui-part="<part-name>"]` (canonical, injected by `ui_part()`) → `[ui="<part-name>"]` (back-compat for templates that still call `primitive()` directly with `ui_part_props()`).
4. Builds a `captured` payload (see shape below).
5. Dispatches a `semitexa:ui-event:captured` CustomEvent on `document` with `detail = captured`.
6. Invokes every `window.SemitexaUi.onCapture(fn)` listener.

**What it does NOT do:**

- No `fetch` / `XMLHttpRequest` / `navigator.sendBeacon` / `WebSocket` / `EventSource`.
- No signature verification — `ctx` is treated as opaque and passed through verbatim.
- No `preventDefault` / `stopPropagation` — native browser behavior is preserved.
- No DOM mutation, no state changes, no UI patching.

**Public API:**

```js
window.SemitexaUi.version          // '1.0'
window.SemitexaUi.scan(root?)      // manual rescan; also auto-runs on DOMContentLoaded + MutationObserver
window.SemitexaUi.manifests()      // snapshot list of parsed manifests on the page
window.SemitexaUi.onCapture(fn)    // register listener; returns unsubscribe()
```

**Captured payload shape:**

```js
{
  component:       'platform.field',
  instanceId:      'uci_<16hex>',
  part:            'input',
  event:           'change',
  updates:         'value',          // or null when the part is unbound
  ctx:             'sc1.<b64>.<b64>',// opaque signed blob — exactly what the future server will receive
  value:           <part.value>,      // extracted from partEl.value or the value attribute
  originalEvent:   <DOM Event>,
  manifestVersion: 1
}
```

**Part-element lookup**: the runtime first looks for `[data-ui-part="<part-name>"]` inside the component root (canonical — emitted by the server-side `ui_part()` Twig helper), then falls back to `[ui="<part-name>"]` for legacy templates that render the primitive directly via `primitive()` + `ui_part_props()`. The canonical path decouples the UiPart name from the primitive's `ui` alias, so a part can be named freely (e.g. `UiPart(name: 'main', uses: InputPrimitive::class)`) without breaking the runtime.

## HTTP dispatch endpoint (ack + response patches)

Bridges captured frontend events to declared `#[UiOn]` handlers through a unified HTTP endpoint. The handler can return either a plain ack or a small list of safe DOM-patch instructions the frontend applies after dispatch.

**Endpoint:** `POST /__ui/dispatch`

Why not `/__ui/event`: SSR ships a foundation-layer placeholder at `/__ui/event` that accepts the framework-layer `UiEventEnvelope` shape (`schemaVersion`, `eventId`, `correlationId`, `semanticEvent`, `signedContext`, `timestamp`, …). Platform UI's dispatcher uses a *minimal* `{ctx, dispatchId, payload}` body — a layered concern that does not need the full framework-layer envelope. The two endpoints will be unified in a future framework-layer slice that introduces a `UiInteractionDispatcherInterface` contract.

**Request shape:**

```json
{
  "ctx": "sc1.<base64url-claims>.<base64url-hmac>",
  "dispatchId": "ui_evt_<32 hex>",
  "payload": { "value": "taras@example.com" }
}
```

- `ctx` is required.
- `dispatchId` is required. Must match `[A-Za-z0-9][A-Za-z0-9_-]{4,127}`. The frontend transport mints one fresh value per captured event with `crypto.getRandomValues` (format: `ui_evt_<32 hex>`).
- `payload` is optional and defaults to `{}`.
- `payload` **must not** carry any routing-flavored field. The `UiPayloadFieldGuard` walks the whole payload tree and rejects (400) on any key (normalized across camelCase/snake_case/kebab-case) matching: `handler`, `handlerId`, `handlerClass`, `handlerMethod`, `method`, `methodName`, `class`, `className`, `component`, `componentName`, `instance`, `instanceId`, `part`, `partName`, `event`, `eventName`, `updates`, `updatesPath`, `endpoint`, `url`, `route`, `action`, `controller`, `callback`, `dispatcher`, `payloadClass`, `authzScope`, `backendHandler`, plus `dispatchId`/`requestId`/`eventId` (those identifiers belong at the top level, not inside `payload`).

**Replay guard.** The dispatcher keys an entry by `sha256(ctx) + ':' + dispatchId`. The TTL is bounded by both the signed ctx's remaining lifetime and a server-side ceiling (currently 600s). A second request with the *same* `(ctx, dispatchId)` pair returns `409 duplicate_dispatch`. Crucially, the same `ctx` with a *different* `dispatchId` still works — the signed ctx is intentionally reusable inside its TTL so legitimate repeated user actions (e.g. successive `change` events on the same field) are not blocked. The replay guard claim is taken **after** ctx verification (so an invalid ctx never poisons the store) and **before** authorization (so a denied attempt still consumes its `dispatchId` — clients must mint a fresh id to retry).

**Authorization hook.** A pluggable `UiInteractionAuthorizerInterface` runs *after* the replay claim and *before* the `#[UiOn]` handler. The default `AllowAllUiInteractionAuthorizer` is wired by the package and allows every verified dispatch; apps swap it via `withServices(authorizer: …)` or a future container binding. A `false` return maps to `403 interaction_forbidden`; the handler is never invoked and no patches are returned.

**Success response (200):**

```json
{
  "ok": true,
  "handled": true,
  "kind": "ack",
  "dispatchId": "ui_evt_<32 hex>",
  "component": "platform.field",
  "instance": "uci_<hex>",
  "part": "input",
  "event": "change",
  "updates": "value",
  "debug": { "value": "taras@example.com", "instance": "uci_<hex>" },
  "patches": []
}
```

The server echoes `dispatchId` on both success and error responses (when it was parseable) so clients can correlate request, lifecycle event, and reply.

**Error responses (safe JSON; never leak class/method names or stack traces):**

| Status | `reason` token | Trigger |
|---|---|---|
| 400 | `empty_body` | Request body is empty |
| 400 | `malformed_json` | Body is not valid JSON |
| 400 | `body_not_object` | Body is a list/scalar, not a JSON object |
| 400 | `missing_ctx` | `ctx` is missing or empty |
| 400 | `missing_dispatch_id` | `dispatchId` is missing or empty |
| 400 | `invalid_dispatch_id` | `dispatchId` fails the format check |
| 400 | `payload_not_object` | `payload` is a list/scalar |
| 400 | `forbidden_payload_field` | Payload smuggled a routing-flavored key (path included in message) |
| 403 | `invalid_signed_ctx` | Signature verify failed OR ctx expired |
| 403 | `updates_path_mismatch` | Signed `u` claim doesn't equal the registered `#[UiOn]` updates path |
| 403 | `interaction_forbidden` | `UiInteractionAuthorizerInterface::authorize()` returned `false` |
| 503 | `ui_replay_store_not_shared` | Production-like env + the bound replay store reports `isShared() === false`. Operator must set `CACHE_DRIVER` to a shared driver (e.g. `redis`). |
| 404 | `unknown_component` | Signed component doesn't exist in `UiComponentRegistry` |
| 404 | `unknown_part` | Signed part doesn't exist on the component |
| 404 | `unknown_event` | Signed (part, event) pair has no `#[UiOn]` |
| 409 | `duplicate_dispatch` | `(ctx, dispatchId)` already processed (replay guard) |
| 422 | `missing_claim_<key>` | Signed context missing required claim |
| 422 | `cannot_instantiate_component` | Component constructor requires DI args |
| 422 | `handler_error` | Handler threw a non-`UiInteractionException` |
| 422 | `invalid_handler_return` | Handler returned something other than void / array / `UiInteractionResult` |
| 422 | `invalid_patch` / `invalid_patch_op` / `patch_instance_mismatch` / `invalid_patch_value` / `invalid_patch_attribute` / `invalid_patch_target_part` / `invalid_patch_target_name` | A handler returned a `UiInteractionResult::patch(...)` that fails server-side patch validation. Errors carry safe reason tokens — no class/method leaks. |
| 500 | `internal_error` | Truly unexpected failure |

Additional forbidden payload keys for the response-patch slice (rejected with `400 forbidden_payload_field`): `patch`, `patches`, `target`, `selector`, `html`, `script`.

**`UiInteractionDispatcher` API:**

```php
final class UiInteractionDispatcher
{
    public function __construct(
        UiPayloadFieldGuard              $payloadGuard   = new UiPayloadFieldGuard(),
        UiPatchValidator                 $patchValidator = new UiPatchValidator(),
        UiReplayStoreInterface           $replayStore    = new InMemoryUiReplayStore(),
        UiInteractionAuthorizerInterface $authorizer     = new AllowAllUiInteractionAuthorizer(),
    );
    public function dispatch(string $ctx, string $dispatchId, array $payload): UiInteractionResult;
}
```

Trust boundary:
- The signed ctx is the **only** source of (component, instance, part, event, updates) identity. Mismatches between signed claims and registry metadata fail closed.
- The request body's `payload` is treated as arbitrary user data **after** `UiPayloadFieldGuard` has scrubbed any routing-flavored keys (the guard runs **before** signature verification so a malformed/tampered ctx still emits a 400 if the payload tries to smuggle a handler).
- `dispatchId` is client-supplied and used only for replay-key construction — handlers see it on `UiInteractionEvent::$dispatchId` for correlation but MUST NOT base authorization or routing decisions on it.
- When the handler returns `UiInteractionResult::patch([...])`, every patch is validated against the signed claims' `instance` — handlers can only patch their own component instance.

**Service bindings (default).** Platform UI ships three `#[SatisfiesServiceContract]`-bound defaults:

| Interface | Default implementation | Module |
|---|---|---|
| `UiReplayStoreInterface` | `CacheBackedUiReplayStore` | semitexa-platform-ui |
| `UiInteractionAuthorizerInterface` | `AllowAllUiInteractionAuthorizer` | semitexa-platform-ui |
| `UiFieldRuleRegistryInterface` | `DefaultUiFieldRuleRegistry` | semitexa-platform-ui |

The Semitexa container resolves both contracts at boot via `ServiceContractRegistry`. `UiDispatchHandler` declares them as `#[InjectAsReadonly]` protected properties — the container fills them, the handler never news them up in production. The dispatcher is constructed inside the handler with the injected dependencies; there is no longer any `withServices()` plumbing on the production path.

**Override seam.** An application registers its own implementation by declaring a class with `#[SatisfiesServiceContract(of: UiInteractionAuthorizerInterface::class)]` (or `UiReplayStoreInterface::class`) inside a module that "extends" `semitexa-platform-ui`. The contract registry picks the descendant-module winner, so the app's class replaces the default automatically — no per-handler wiring required.

**Replay store implementations.**

- `CacheBackedUiReplayStore` — **production default**. Backed by `Semitexa\Cache\Domain\Contract\CacheManagerInterface` under the `ui-dispatch-replay` namespace; inherits the cache's process-shared semantics. `isShared()` reports `true` when the bound cache driver is `redis`, `valkey`, or `memcached`. With `CACHE_DRIVER=array` (the framework default), each Swoole worker has its own in-memory cache → `isShared()` reports `false` → the dispatcher refuses to invoke handlers in production-like environments.
- `InMemoryUiReplayStore` — test/dev fallback. Always reports `isShared() === false`. Used only by tests that construct `UiDispatchHandler` directly without a container. Apps and modules MUST NOT wire this with `#[SatisfiesServiceContract]`; it carries no such attribute on purpose.

**Runtime guard.** Before claiming a replay key, `UiInteractionDispatcher` calls `$replayStore->isShared()`. In production-like environments (`APP_ENV` is `prod` or `production`), a `false` return aborts the dispatch with `503 ui_replay_store_not_shared`. The handler is never invoked. In other environments (`dev`, `staging`, `test`, …) the guard is a no-op so local development with the in-memory store continues to work. The check runs *after* ctx verification (so a tampered ctx still surfaces the documented `403 invalid_signed_ctx`) and *before* the replay claim (so an unsafe store never accumulates orphan keys).

Why signed `ctx` is reusable but `dispatchId` is single-use: the signed `ctx` carries identity (`c, i, p, e, u, iat, exp`) so the dispatcher can resolve handlers — re-issuing it on every keystroke would force a server round-trip per character. The `dispatchId` is the *attempt* identifier and exists exclusively for replay deduplication: each captured event mints a fresh `crypto.getRandomValues`-derived id, so a network race or double-click produces two distinct ids and both succeed, but an exact replay (`ctx, dispatchId` pair) is rejected at the replay claim.

Why shared replay cache is required: with `CACHE_DRIVER=array`, two requests carrying the same `(ctx, dispatchId)` that land on different Swoole workers both see "no claim yet" in their per-worker arrays and both succeed. With `CACHE_DRIVER=redis` (or `valkey`/`memcached`), the claim is observable across every worker on the same node. **The shipped configuration on `semitexa-pl` sets `CACHE_DRIVER=redis` in `.env` for this reason.**

**`UiInteractionEvent` DTO** passed to handlers:

```php
final readonly class UiInteractionEvent
{
    public string $componentName;
    public string $instanceId;
    public string $partName;
    public string $eventName;
    public ?UiValuePath $updatesPath;
    public array $payload;       // already guard-scrubbed
    public int $issuedAt;
    public int $expiresAt;
    public array $claims;        // raw signed claims (server-side only)
    public string $dispatchId;   // per-attempt id; correlation only — do NOT route on it
}
```

**`UiInteractionResult` DTO:**

```php
final readonly class UiInteractionResult
{
    public const KIND_ACK   = 'ack';
    public const KIND_PATCH = 'patch';
    public string $kind;
    public array $debug;
    /** @var list<UiResponsePatch> */
    public array $patches;
    public static function ack(array $debug = []): self;
    public static function patch(array $patches, array $debug = []): self; // empty list ↦ kind=ack
}
```

Handlers may also return `void` (mapped to `ack()`), a bare `array` (mapped to `ack($array)`), or an explicit `UiInteractionResult`.

**`UiResponsePatch` DTO:**

```php
final readonly class UiResponsePatch
{
    public const OP_SET_TEXT      = 'setText';
    public const OP_SET_VALUE     = 'setValue';
    public const OP_SET_ATTRIBUTE = 'setAttribute';
    public const ALLOWED_OPS;        // [setText, setValue, setAttribute]
    public const ALLOWED_ATTRIBUTES; // [aria-invalid, aria-describedby, data-state, ui-state]

    public string $op;
    public string $targetInstance;   // must match signed claims' instance
    public ?string $targetPart;      // resolved as [data-ui-part="<name>"] inside the root
    public ?string $targetName;      // resolved as [data-ui-patch-target="<name>"] inside the root
    public mixed  $value;            // scalar / null
    public ?string $attribute;       // setAttribute only; must be in ALLOWED_ATTRIBUTES
}
```

Targeting addresses (ALL scoped to a single component instance — no global/`document` selectors):

| target shape                          | resolves to                                                             |
| ---                                   | ---                                                                     |
| `{ instance }`                        | the component root                                                      |
| `{ instance, part }`                  | `[data-ui-part="<part>"]` inside the root                               |
| `{ instance, name }`                  | `[data-ui-patch-target="<name>"]` inside the root                       |

`UiPatchValidator` (server-side) enforces every rule above and ALSO that `target.instance === claims.i`. The frontend re-checks the same invariants before mutating the DOM.

**FieldComponent handler (returns a server-ack patch):**

```php
#[UiOn(part: 'input', event: 'change')]
public function onInputChanged(UiInteractionEvent $event): UiInteractionResult
{
    return UiInteractionResult::patch(
        patches: [
            new UiResponsePatch(
                op: UiResponsePatch::OP_SET_TEXT,
                targetInstance: $event->instanceId,
                targetPart: null,
                targetName: 'server-ack',
                value: 'Server received: ' . (string) $event->value(),
            ),
        ],
        debug: ['value' => $event->value(), 'instance' => $event->instanceId],
    );
}
```

The `server-ack` `<span data-ui-patch-target="server-ack">` is **opt-in** per render — only emitted when the caller passes `showServerAckTarget: true` to `component('platform.field', ...)`. When the target is absent, the frontend applier emits a `semitexa:ui-patch:failed` lifecycle event for that patch and does nothing — the DOM stays unchanged.

**Response JSON (success with patches):**

```json
{
  "ok": true,
  "handled": true,
  "kind": "patch",
  "component": "platform.field",
  "instance": "uci_<hex>",
  "part": "input",
  "event": "change",
  "updates": "value",
  "debug": { "value": "taras@example.com", "instance": "uci_<hex>" },
  "patches": [
    {
      "op": "setText",
      "target": { "instance": "uci_<hex>", "name": "server-ack" },
      "value": "Server received: taras@example.com"
    }
  ]
}
```

For ack-only responses the `patches` field is `[]` and `kind` stays `"ack"`.

## Frontend transport bridge (opt-in)

`window.SemitexaUi.transport.attach({ endpoint })` subscribes the capture pipeline to an HTTP endpoint. Until `attach` is called, the runtime makes **zero** network requests — `fetch(` lives only inside `transport.attach`'s closure body.

```js
// Opt-in transport hookup (per page).
const detach = window.SemitexaUi.transport.attach({ endpoint: '/__ui/dispatch' });
// later: detach();
```

Wire body sent on every capture: **exactly** `{ ctx, dispatchId, payload: { value } }`. The `dispatchId` is freshly minted per captured event with `crypto.getRandomValues` (format: `ui_evt_<32 hex>`), so a network race or double-click produces two distinct ids and both go through; only an *exact* `(ctx, dispatchId)` replay is rejected with `409`. Never component, instance, part, event, handler, method, class, endpoint, url, action, dispatcher fields.

Lifecycle CustomEvents on `document` (every detail carries `dispatchId` for correlation):
- `semitexa:ui-event:dispatching`  (before fetch; `detail = {captured, dispatchId, endpoint}`)
- `semitexa:ui-event:dispatched`   (on 2xx; `detail = {captured, dispatchId, status, response}`)
- `semitexa:ui-event:failed`       (on non-2xx or thrown; `detail = {captured, dispatchId, status?, response?, error?, phase}`)
- `semitexa:ui-patch:applied`      (one per successfully applied response patch; `detail = {patch, captured, index}`)
- `semitexa:ui-patch:failed`       (one per response patch that could not apply; `detail = {patch, captured, index, reason}`)

The bridge does **not** call `preventDefault` / `stopPropagation`.

## Frontend response-patch applier

When the server response includes a non-empty `patches` array, the bridge runs each patch through a tight safe applier with these invariants:

- Allowed ops are exactly `setText`, `setValue`, `setAttribute`. Anything else → `semitexa:ui-patch:failed` with `reason: "invalid_op"`.
- `setAttribute` is gated by the `aria-invalid` / `aria-describedby` / `data-state` / `ui-state` allow-list.
- The component root is located by `document.querySelector('[data-ui-component-instance-id="<safe-id>"]')`. The patch target is then resolved by `rootEl.querySelector(...)` for `[data-ui-part="<safe-name>"]` or `[data-ui-patch-target="<safe-name>"]`. The applier never accepts a caller-provided CSS selector and never traverses outside the component root.
- The applier uses `textContent`, `element.value`, `setAttribute` / `removeAttribute` only. It never touches `innerHTML`, `outerHTML`, `insertAdjacentHTML`, `document.write`, `eval`, or the `Function` constructor.
- Patch identifiers (instance, part, name) must match `/^[A-Za-z_][A-Za-z0-9_-]*$/`. Anything else fails before any DOM lookup.
- One failed patch never breaks the rest of the batch — each patch fires its own `:applied` or `:failed` event.
- The bridge also double-checks `target.instance === captured.instanceId` before applying — defense in depth against a tampered response.

## SSE server-push channel

> **Retired.** The standalone platform-ui patch-stream subsystem (its own
> route, channel-token auth, per-channel Redis queue, connection limiter and
> subscription authorizer) has been **removed**. All UI streaming now rides the
> single canonical KISS stream `GET /__semitexa_kiss` (served by SSR's
> `AsyncResourceSseServer::handleSse`). The page opens that one stream via
> `ui_page_sse_session_meta(...)`; components never open their own.

What survives is the part that was always shared: there is **one** patch shape
(`UiResponsePatch`) and **one** safe applier on the frontend. Server-pushed
patches arrive as canonical typed `ui.patch` frames on the KISS `EventSource`
and flow through the same `applyOnePatch` engine the request/response transport
uses. The frontend bridge (`window.SemitexaUi.sse.attach({url})`) is only ever
attached to a `/__semitexa_kiss` URL; it still emits the `semitexa:ui-sse:*`
lifecycle CustomEvents on `document`. The retired `{v, patches[]}` envelope is
tolerated defensively but is no longer produced.
