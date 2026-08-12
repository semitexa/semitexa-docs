---
id: rendering/ui-forms
section: rendering
slug: ui-forms
title: UI Forms
summary: Composing platform.form, the authoritative submit pipeline, per-field signed projection and the playground.
order: 190
locale: en
status: canonical
keywords:
  - platform.form
  - submit pipeline
  - cfg.f.i
  - playground
---

# UI Forms

## Form composition (`platform.form`)

A minimal composition container for grouping fields and surfacing a *client-local* aggregate of their server-validated state. **Not a form engine** — no real submit, no persistence, no CSRF, no server-side form-state store, no cross-field rules, no async validation. Field rules continue to run server-side through `FieldComponent`'s existing pipeline; the form layer never re-evaluates rules.

```twig
{% set _fields %}
    {{ component('platform.field', {
        label: 'Username',
        name: 'username',
        showValidationTarget: true,
        rules: ['required', ['minLength', 3], ['maxLength', 20]],
    }) }}
    {{ component('platform.field', {
        label: 'Display name',
        name: 'display_name',
        showValidationTarget: true,
        rules: [['maxLength', 12]],
    }) }}
{% endset %}

{{ component('platform.form', {
    title: 'Sign-up details',
    description: 'Validation runs server-side; the aggregate is client-local.',
    showStatus: true,
    showSubmit: true,
    submitText: 'Create account',
}, {
    content: _fields,
}) }}
```

**Component props** (all optional):

| Prop | Type | Default | Notes |
|---|---|---|---|
| `title` | string | `null` | Rendered as `<h2 ui-text="title">` when set. |
| `description` | string | `null` | Muted paragraph beneath the title. |
| `showStatus` | bool | `true` | Renders the `data-ui-patch-target="form-status"` target. |
| `statusInitialMessage` | string | `'No fields validated yet.'` | Text shown before any field has validated. |
| `showSubmit` | bool | `false` | Renders a `platform.button` shell — **visual only**, no submit pipeline. |
| `submitText` | string | `'Submit'` | Button label. |
| `ariaLabel` | string | `null` | Accessible name when the visual title is absent. |

**Slot**: `content` — caller-provided markup, typically one or more `FieldComponent`s. Passed as the third argument of `component('platform.form', props, { content: … })`.

**Rendered shape**:

```html
<div data-ui-component="platform.form"
     data-ui-component-instance-id="uci_<16hex>"
     data-ui-form-aggregate="1"
     ui-component="form"
     sx-layout="stack" sx-gap="4"
     role="group">
  <h2 ui-text="title">Sign-up details</h2>
  <p ui-text="muted">Validation runs server-side…</p>
  <div data-ui-form-fields sx-layout="stack" sx-gap="3">
    <!-- caller's content slot — usually one or more FieldComponents -->
  </div>
  <div data-ui-patch-target="form-status" aria-live="polite" role="status" ui-text="muted">
    No fields validated yet.
  </div>
  <!-- optional visual submit -->
</div>
```

**Field-name marker**. `FieldComponent` now stamps `data-ui-field-name="<name>"` on its root **only when the `name` prop matches the safe identifier shape `[A-Za-z_][A-Za-z0-9_-]*`** — the same pattern the patch validator accepts for target names. Anything else is dropped silently. Anonymous fields fall back to the field's `data-ui-component-instance-id` as the aggregate key so they still aggregate distinctly.

**Aggregation runtime** (in `event-runtime.js`):

After every successful dispatch response, the transport bridge calls `updateFormAggregate(response, captured)`:

1. Reads `response.debug.validation.{state,message}` — the shape `FieldComponent::onInputChanged()` already returns. **No new wire shape.**
2. Resolves the enclosing form root by walking up the DOM from the field root, looking for the nearest ancestor matching `[data-ui-form-aggregate="1"][data-ui-component-instance-id]`. If no form root is found, aggregation is a graceful no-op.
3. Updates a module-local map `{ formInstance → { fields: { fieldKey → {state, message} }, lastAt } }`. The key is `data-ui-field-name` when set, the field's instance id otherwise. **Repeated updates for the same field overwrite — never duplicate — the entry.**
4. Computes a summary: `{knownCount, invalidCount, validCount, aggregateState, message}`. `aggregateState` is `'invalid'` if any known field is invalid, `'valid'` if all known fields are valid, `'pending'` before the first field validates.
5. Synthesises **two patches** targeting the form root:
    - `{op: 'setText', target: {instance: <form>, name: 'form-status'}, value: <message>}`
    - `{op: 'setAttribute', target: {instance: <form>}, attribute: 'ui-state', value: <aggregateState>}`
6. Feeds them through the existing `applyOnePatch` — the **same safe applier** the dispatch transport and the SSE bridge use. No new mutation engine, no new patch op, no expansion of the attribute allow-list.
7. Dispatches a `semitexa:ui-form:aggregate` CustomEvent on `document` so consumers can mirror the snapshot without re-deriving it.

**Public API** (kept narrow):

- `window.SemitexaUi.forms.snapshot(formInstance?)` — returns `{formInstance, fields, summary}` for one form, or a map keyed by form instance when called without an argument. `null` for an unknown id.
- `window.SemitexaUi.forms.reset(formInstance?)` — drops one form's aggregate, or all of them.

**Aggregation status messages** (verbatim contract, pinned by tests):

| Aggregate state | Message |
|---|---|
| no fields known | `No fields validated yet.` |
| 1 invalid field | `1 field needs attention.` |
| N invalid fields | `<N> fields need attention.` |
| 1 valid field, 0 invalid | `1 field validated — looks good.` |
| N valid fields, 0 invalid | `All <N> validated fields look good.` |

**What this slice does NOT introduce**:

- No real form submit, no persistence, no session-backed form state, no server-side form state store.
- No CSRF / submit pipeline / file uploads / multi-step navigation.
- No cross-field rules, no field dependency rules, no schema validation.
- No client-side rule mirror. `UiFieldValidator` stays server-only.
- No async validation, no WebSockets, no new SSE semantics.
- No HTML patches, no `innerHTML`, no `eval`, no arbitrary selectors. Same allow-list as before.
- No new `UiResponsePatch` op. Aggregation reuses `setText` + `setAttribute`.
- No `disabled` attribute mutation — `disabled` remains off the patch allow-list on purpose. The submit button is visual only in this slice.
- No persistent server-side form snapshot. State is per-page-load, per-tab. A reload resets the aggregate; broadcasting (e.g. via SSE) is future work.

## Form submit pipeline (authoritative final validation)

FormComponent now declares `#[UiOn(part: 'form', event: 'submit')]` so the dispatcher routes a verified submit ctx to `FormComponent::onSubmit`. Submit is the **authoritative** counterpart to the input-change cross-field path: it revalidates every signed field rule against the submitted snapshot before returning a result.

**Caller API**:

```twig
{{ component('platform.form', {
    title: 'Submit validation demo',
    showStatus: true,
    showSubmit: true,
    submitText: 'Validate form',
    fields: [
        {
            name: 'access_code',
            label: 'Access code',
            required: true,
            rules: ['required', ['minLength', 4]],
        },
        {
            name: 'confirm_access_code',
            label: 'Confirm access code',
            required: true,
            rules: [
                'required',
                ['sameAsField', 'access_code', 'Codes must match.'],
            ],
        },
    ],
}, { content: _fields }) }}
```

The `fields` prop is the **server-owned** definition of which fields participate in submit, with what labels and which rules. It is normalised through `UiFormSubmitConfigParser` at render time (delegating to the active `UiFieldRuleParser`) and signed into `cfg.f` of the submit ctx.

### `autoFields` — derive cfg.f from slotted FieldComponents

`FormComponent` also accepts `autoFields: true`, which derives `cfg.f` directly from the FieldComponents rendered inside the form's `content` slot — no manual `fields` prop required. Mechanism:

1. The form template opens a **render-scope collector frame** (`UiFormSubmitDefinitionCollector::open()`) **before** rendering the content slot.
2. The slot output is captured into a Twig variable, so every slotted FieldComponent runs while the frame is open.
3. Each FieldComponent's template calls `ui_form_field_register({ n, i, r, l, q })` after computing its own instance id and normalised rule wire. Safe-named fields (matching `[A-Za-z_][A-Za-z0-9_-]*`) register into the active frame; unsafe / anonymous names render normally but do not register.
4. After the slot has rendered, `ui_form_resolve_submit_fields(frameToken, autoFields, manualFields)` closes the frame, returns the collected wire shape, and the form template signs it into `cfg.f` as usual.

The collector is a **process-local stack** of frames keyed by an opaque token. Nested forms push their own frame; outer fields stay isolated from inner ones. The token returned from `open()` must match the one passed to `close()` — a mismatch fails loud so a template bug surfaces rather than silently leaking definitions across renders. `reset()` is the explicit recovery hook for tests and worker boundaries.

**Caller-side DX**:

```twig
{% set _fields %}
    {{ component('platform.field', {
        name: 'access_code',
        label: 'Access code',
        required: true,
        showValidationTarget: true,
        rules: ['required', ['minLength', 4]],
    }) }}
    {{ component('platform.field', {
        name: 'confirm_access_code',
        label: 'Confirm access code',
        required: true,
        showValidationTarget: true,
        rules: ['required', ['sameAsField', 'access_code', 'Codes must match.']],
    }) }}
{% endset %}

{{ component('platform.form', {
    showStatus: true,
    showSubmit: true,
    submitText: 'Validate form',
    autoFields: true,
}, { content: _fields }) }}
```

Field instance ids in cfg.f.i are the **same** uci_… ids emitted on each FieldComponent's `data-ui-component-instance-id` — no caller-side bookkeeping. Per-field submit projection works automatically.

**autoFields behaviour matrix**:

| `autoFields` | `fields` prop | Result |
|---|---|---|
| `true` | not provided or empty | cfg.f derived from collected FieldComponents |
| `true` | non-empty | **throws `UiComponentRegistryException` at render time** (ambiguous; pick one) |
| `false` (default) | non-empty | cfg.f parsed from manual `fields` (legacy path) |
| `false` (default) | not provided or empty | no cfg.f signed; submit handler returns `no_signed_fields` |

The collector frame opens unconditionally (so a no-op happens whether or not autoFields is on) and always closes — manual-mode renders simply collect an empty list and discard it.

### `submitAction` — typed submit-action seam

`FormComponent` accepts an optional `submitAction` prop naming a server-registered action. When the form's authoritative validation passes, the form template-time signed action name (`cfg.a`) is resolved through `UiFormSubmitActionRegistryInterface`, the action is invoked with a typed `UiFormSubmitActionContext`, and its `UiFormSubmitActionResult` becomes the final form-status + ui-state.

```twig
{{ component('platform.form', {
    autoFields: true,
    showSubmit: true,
    submitText: 'Validate form',
    submitAction: 'platform.demo.accept',
}, { content: _fields }) }}
```

**Action contract** (`Semitexa\PlatformUi\Application\Service\Submit\UiFormSubmitActionInterface`):

```php
interface UiFormSubmitActionInterface
{
    public function name(): string;
    public function handle(UiFormSubmitActionContext $context): UiFormSubmitActionResult;
}
```

**Action context** — read-only value object carrying:
- `formInstanceId` (the form's render-time uci_ id);
- `actionName` (the resolved registry name);
- `dispatchId` (correlation only — dispatcher already consumed it for replay protection);
- `values` (sanitised `payload.form.values` snapshot — still client-controlled, treat as untrusted);
- `fields` (signed `UiFormSubmitFieldDefinition` list);
- `submitResult` (authoritative validation summary — always `valid` by the time `handle()` is called).

The context never includes the raw `SignedContext` token, the `Request` object, container services, or secrets.

**Action result** — `{accepted: bool, message: string, debug?: array, extraPatches?: list<UiResponsePatch>}`. Two factories:
- `UiFormSubmitActionResult::accepted($message)` → `accepted=true`;
- `UiFormSubmitActionResult::rejected($message)` → `accepted=false`.

The message becomes `setText form-status`; `accepted` maps to `setAttribute ui-state = valid|invalid`. Extra patches are validated through the same `UiPatchValidator` the rest of the pipeline uses — actions cannot target unsigned instances or use unallow-listed ops.

**Registry contract** (`UiFormSubmitActionRegistryInterface`):

```php
interface UiFormSubmitActionRegistryInterface
{
    public function resolve(string $actionName): UiFormSubmitActionInterface;
    public function knownActionNames(): array;
}
```

Apps register their own implementation with `#[SatisfiesServiceContract(of: UiFormSubmitActionRegistryInterface::class)]` in a module that "extends" `semitexa-platform-ui`. Compose with `DefaultUiFormSubmitActionRegistry` to inherit `platform.demo.accept`:

```php
#[SatisfiesServiceContract(of: UiFormSubmitActionRegistryInterface::class)]
final class AppFormSubmitActionRegistry implements UiFormSubmitActionRegistryInterface
{
    public function __construct(private DefaultUiFormSubmitActionRegistry $builtins = new DefaultUiFormSubmitActionRegistry()) {}

    public function resolve(string $actionName): UiFormSubmitActionInterface
    {
        return match ($actionName) {
            'app.signup.preview' => new SignupPreviewAction(),
            default              => $this->builtins->resolve($actionName),
        };
    }

    public function knownActionNames(): array
    {
        return [...$this->builtins->knownActionNames(), 'app.signup.preview'];
    }
}
```

The registry MUST resolve through a fixed `match` — NEVER `new $name(...)` or `class_exists($name)`. Custom registries MUST honour the same perimeter.

**Behaviour matrix**:

| Validation | `submitAction` | Result |
|---|---|---|
| valid | absent | existing accepted summary (`"Form is valid. Submit accepted."`); no `debug.action` key |
| valid | registered name | action invoked; `form-status` ← action message; `ui-state` ← `valid` if accepted, `invalid` if rejected; `debug.action` carries `{name, accepted, message}` |
| invalid | absent | existing invalid summary |
| invalid | registered name | action **not invoked**; existing invalid summary; `debug.action` ← `{name, invoked: false, reason: 'validation_invalid'}` |
| any | unknown name | render-time `UiFormSubmitActionException` (the form fails to render in dev) |
| any | unsafe shape | render-time `UiFormSubmitActionException` |
| any | tampered `cfg.a` | dispatcher returns `403 invalid_signed_ctx` (HMAC fails) |
| any | client payload smuggling | `400 forbidden_payload_field` (UiPayloadFieldGuard) |

`payload.action`, `payload.submitAction`, `payload.form.submitAction` and case-variant siblings (`submit_action`, `submit-action`) are all forbidden in the request body.

**Built-in actions**:

- **`platform.demo.accept`** (`PlatformDemoAcceptAction`) — inert. Returns `UiFormSubmitActionResult::accepted('Demo action accepted. No data was persisted.')` with safe debug counts only. Does not persist, send email, redirect, or echo raw values.
- **`platform.demo.storeContact`** (`PlatformDemoStoreContactAction`) — first persistent demo. Allow-lists `contact_name` / `contact_message` / `contact_topic` from the sanitised snapshot, trims them, drops empties, and saves a `UiFormDemoSubmissionRecord` through `UiFormDemoSubmissionRepositoryInterface` (cache-backed, 24h TTL). Returns `'Demo submission saved. No external side effects were performed.'` with `debug.action.detail = {stored, submissionId, storedFieldCount}` (never raw values).
- **`platform.demo.storeContactDb`** (`PlatformDemoStoreContactDbAction`) — first **database-backed** demo. Identical sanitisation contract as the cache-backed sibling, but persists through `UiFormDatabaseDemoSubmissionRepositoryInterface` — production-bound to `UiFormDemoSubmissionDbRepository` (ORM-backed, `#[SatisfiesRepositoryContract]`, table `platform_ui_demo_submissions`). Returns `'Demo submission saved to the database. No external side effects were performed.'` with `debug.action.detail = {stored, submissionId, storage: "database", storedFieldCount}`. Durable until you delete the row — still demo storage, not a real CRM.

**First project-side business action** (lives outside this package, in the UiPlayground module):

- **`ui-playground.lead.store`** (`Semitexa\Modules\UiPlayground\Application\Service\Submit\Action\UiPlaygroundStoreLeadAction`) — first **project-owned** business action. Proves the seven-gate pipeline is extensible from the project side without touching the package. Allow-lists `lead_name` / `lead_company` / `lead_message`, trims, drops empties + non-scalars, generates a `uilead_<16hex>` id, and saves a `UiPlaygroundLeadSubmissionRecord` through the project-side `UiPlaygroundLeadSubmissionRepositoryInterface` (ORM-backed, table `ui_playground_leads`). Returns `'Lead request saved. No email or external side effects were performed.'` with `debug.action.detail = {stored, leadId, storage: "database", storedFieldCount}` (never raw values). No email, no redirect, no external API, no export, no edit/delete, no async — those are explicit future slices.

  **Read-only admin listing** at `GET /ui-playground/admin/leads`. Mirrors the package-side demo-submissions diagnostic listing shape: project-side `LeadAdminPayload` / `LeadAdminHandler` / `LeadAdminResponse` + `lead-admin.html.twig`. Reads through `UiPlaygroundLeadSubmissionRepositoryInterface` (`DEFAULT_RECENT_LIMIT = 25`, `MAX_RECENT_LIMIT = 100`, newest-first via `ORDER BY submitted_at DESC, id DESC`). View-model is `{id, actionName, formInstanceId, submittedAt, leadName, leadCompany, leadMessagePreview (≤160+ellipsis), storedFieldCount}` — `values_json` never reaches the template; tokens / ctx / dispatchId / debug never reach the page. **No search, no edit, no delete, no export** — explicit non-goals.

  **Cursor pagination + search/filter**. Same keyset-pagination + filter-fingerprint pattern as the package-side demo listing, project-namespaced to avoid coupling to demo-submission naming:

  | Query param | Default | Behaviour |
  |---|---|---|
  | `limit`  | `25` (`DEFAULT_RECENT_LIMIT`) | Clamped server-side to `[1, MAX_RECENT_LIMIT]` (`100`). Non-numeric / empty / negative / whitespace falls back to the default. `0` clamps up to `1`. |
  | `cursor` | _(absent)_ | Opaque base64url token returned by the previous page's `nextCursor`. Empty / missing → first page. Malformed → HTTP 400 `invalid_cursor` with the safe template state and no repository read. Cursor binds to the filter combination it was issued under. |
  | `q`      | _(absent)_ | Bounded diagnostic-grade search term (max `100` UTF-8 characters; longer → HTTP 400 `invalid_search_query`). Trimmed; empty / whitespace → treated as absent. Case-insensitive substring match against the allow-listed lead fields (`lead_name`, `lead_company`, `lead_message`). Not a full-text engine. |
  | `action` | _(absent)_ | Optional allow-listed action filter. The only accepted value today is `ui-playground.lead.store` — the listing surfaces project-side rows only. Any other value (including `platform.demo.*`) → HTTP 400 `invalid_action_filter` and the bad value is never echoed back. |

  **Cursor shape**: `UiPlaygroundLeadSubmissionCursor` is a project-side counterpart of the demo-side `UiFormDemoSubmissionCursor`. Wire format: `base64url(JSON {"s": <int>, "i": "<id>" [, "f": "<16hex>"]})` where the `id` regex enforces `uilead_[a-f0-9]{16}` — distinct from the demo cursor's `uifs_…` shape, so the two listings cannot trade cursors (pinned by a unit test). The optional `f` key is the filter fingerprint, present only when the cursor was minted under a filtered listing. Tight key whitelist (only `{s,i}` or `{s,i,f}`), strict `json_decode(..., JSON_THROW_ON_ERROR)`, type-checked, no `serialize`/`unserialize`/`eval`. Any deviation throws `UiPlaygroundLeadSubmissionCursorException` (reason `invalid_cursor`) with a fixed safe message that never echoes the bad input back.

  **Handler gate ordering** (security-significant):
  1. **Authorization** runs FIRST. A denied caller never sees a 400 for malformed q/action/cursor (no decode oracle on the deny path), and the repository is never read.
  2. **Search criteria** (`q` + `action` + `limit`) are parsed and validated next. Oversize `q` → 400 `invalid_search_query`. Unknown `action` → 400 `invalid_action_filter`. No raw bad input is echoed back; the form re-renders empty.
  3. **Cursor decode** runs only after criteria validation passes. Malformed cursor → 400 `invalid_cursor`. Still no repository read.
  4. **Cursor / filter binding**: the cursor's optional `filterFingerprint` MUST equal the criteria's `fingerprint()`. Mismatch → 400 `invalid_cursor`. A cursor issued under a filtered listing is not reusable as an unfiltered cursor and vice-versa.
  5. **Repository read** runs only after every gate passes — `paginate()` when the criteria is unfiltered, `searchPage()` otherwise.

  **Search semantics & SQL safety**. The ORM impl binds the search term via a parameterised LIKE against the serialised `values_json` column: `WHERE values_json LIKE ? ESCAPE '\\'`. User-supplied `%` / `_` / `\` are escaped in `escapeLike()` before binding so a literal `%` in the search term cannot turn into a wildcard. The escape character `\` is escaped FIRST so a trailing `\` cannot escape the closing `%` of the bound pattern. The `action_name` filter binds via the typed `where(Operator::Equals)` helper, not raw SQL. The search term is NEVER concatenated into SQL — it travels as a `?` placeholder value.

  **Filter fingerprint binding** (cursor v2): the `f` key is the first 16 hex characters of `sha256(query|action)` over the canonical case-folded form. Acceptance:
   - v1 cursor (no `f`) decodes as `filterFingerprint = null`; accepted only for unfiltered requests.
   - v2 cursor with `f = X` accepted only when the active criteria's `fingerprint()` equals `X`.
   - Any other combination → HTTP 400 `invalid_cursor`. Makes splicing a cursor from one filter onto another impossible without inverting sha256.

  **Repository contract**: `paginate(?cursor, $limit)` and `searchPage(criteria, ?cursor)` both fetch `limit + 1` rows for cheap `hasMore` detection, trim, and build `nextCursor` from the LAST returned record when more rows remain. The next-cursor inherits the active criteria's fingerprint (or `null` when unfiltered). `recent($limit)` is `paginate(null, $limit)->records` — same semantics, simpler surface for ergonomic callers.

  **Template Next-page link**: rendered only when `paginationHasMore && paginationNextCursor !== null`. Preserves the active `q` / `action` / `limit` alongside the encoded cursor (URL-encoded by Twig's `url_encode`). The 400 states render "← Back to the first page" links. No JavaScript, no AJAX, no infinite scroll, no "Previous" link in this slice.

  **Access control** for the lead listing is its own project-side seam: `UiPlaygroundLeadAdminAuthorizerInterface`, default `AllowAllUiPlaygroundLeadAdminAuthorizer` (`#[SatisfiesServiceContract]`), opt-in `ConfigurableUiPlaygroundLeadAdminAuthorizer` gated by the env flag `UIPLAYGROUND_LEAD_ADMIN_ENABLED` (truthy = `1`/`true`/`yes`/`on`/`enabled`, case-insensitive after trim; anything else denies with `reason: lead_admin_disabled`). Worker-scoped static holder `UiPlaygroundLeadAdminAuthorizer::{getActive, setActive, reset}`, seeded by the project-side `BootUiPlaygroundRegistryListener` from the container-bound winner. Denial → HTTP 403 + safe template state (`reason` + message; no row data). The denial message NEVER echoes the bad env value, the flag name, or any class FQCN.

  **Registry wiring**: a project-side `UiPlaygroundFormSubmitActionRegistry` implements `UiFormSubmitActionRegistryInterface` and is discovered as the active winner via `#[SatisfiesServiceContract(of: UiFormSubmitActionRegistryInterface::class)]`. It resolves `ui-playground.lead.store` to its own action and delegates every other name to `DefaultUiFormSubmitActionRegistry` — so every existing `platform.demo.*` action keeps working unchanged. **Constructor caveat**: the container instantiates `#[SatisfiesServiceContract]` winners via `newInstanceWithoutConstructor()`, so a composite registry's `__construct` is NOT invoked at container build. Any composed default-registry instance MUST be lazy-initialised (e.g. `private ?DefaultUiFormSubmitActionRegistry $builtins = null;` plus a `$this->builtins ??= new ...` accessor) — initialising the property from `__construct` leaves it uninitialised at runtime and the first `resolve()` call throws a typed-property fatal.

  **Boot listener**: a project-side `BootUiPlaygroundRegistryListener` (`AsServerLifecycleListener` phase `WorkerStartAfterContainer`, priority `0` so it fires AFTER the package's `-5`) stashes the container-bound `UiPlaygroundLeadSubmissionRepositoryInterface` winner in the project's worker-scoped static holder. The composite registry pulls the active repo from that holder lazily at action-resolve time, so the action class itself stays free of container access.

  > **Superseded (One Way Phase 6).** Everything in this `platform.grid`
  > section describes the v1 grid apparatus — `GridComponent`,
  > `grid.html.twig`, `grid-runtime.js`, `UiGridDataResponse`,
  > `GridRuntimeStaticAssertTest` — which was DELETED in the One Way Phase 6
  > sweep. The replacement is the contract-driven `platform.grid-v2` shell
  > (`resources/twig/components/runtime/grid-v2.html.twig`) +
  > `grid-runtime-v2.js`: grids boot from the route's OPTIONS contract and
  > render the canonical `{data, meta}` collection envelope (pull or SSE).
  > The description below is retained as historical design context only.

  **`platform.grid` — reusable interactive grid shell**. A minimal package-level component. **Two consumers** now drive it through identical client-side code: the lead admin listing (`/ui-playground/admin/leads`) and the demo-submissions diagnostic listing (`/ui-playground/admin/demo-submissions`). Each consumer owns its own data endpoint, criteria, cursor, authorizer, and (for leads) SSE topic + publisher — the grid component owns only the shell + the runtime contract.

  - **Component**: `Semitexa\PlatformUi\Application\Component\Builtin\GridComponent` (`#[AsComponent(name: 'platform.grid', template: '@platform-ui/components/runtime/grid.html.twig', cacheable: true)]`). Slots: `warning`, `filters`, `filterState`, `footer` — all caller-owned. The component owns ONLY the grid shell (root + data-* attrs + hidden refresh marker + table headers from `columns` + initial-rows fallback tbody + Next-link with fallback href + inline JSON bundle). It deliberately does NOT know query semantics, authorization, repository, cursor internals, or SSE topic internals.

  - **Caller props**: `gridId` (required), `instanceId` (optional, falls back to `ui_component_instance()`), `dataUrl` (required), `sseUrl` (optional), `refreshMarker` (defaults to `grid-refresh-marker`; callers set this to whatever name their server-side publisher targets — the lead listing uses `lead-grid-refresh-marker`), `columns` (list of `{key, label, style?, sortAsc?, sortDesc?}` — the runtime renders rows in this exact order, ignoring extra keys; `sortAsc` + `sortDesc` opt a column into the sortable-header UI), `initialRows` + `initialPagination` (server-rendered fallback), `initialQuery` + `initialAction` + `initialSort` + `sortParam` + `pageFallbackUrl` (no-JS Next-link + sort-toggle href composition; `sortParam` defaults to `sort` and matches the caller's data-endpoint contract), `emptyMessage` (empty-state copy).

  - **Sortable column headers** (lead grid + demo-submissions grid, both at parity). When a column map carries BOTH `sortAsc` + `sortDesc` allow-listed tokens, the template renders the header label as `<a data-ui-grid-sort data-ui-grid-sort-asc="..." data-ui-grid-sort-desc="...">` with an `aria-sort="ascending|descending|none"` on the surrounding `<th>` and a small toggle indicator (`▲`/`▼`/`↕`). The toggle `href` is composed server-side from `pageFallbackUrl` + active filter state + the *other* direction's token (so the no-JS path works without JS). The runtime intercepts clicks, flips between `sortAsc` and `sortDesc` based on the current `state.sort`, clears the cursor, mirrors the new sort into the caller-owned hidden `<input name="sort">` inside the filter form, updates the aria-sort + toggle-href + indicator glyph immediately, and reloads. The tokens are SERVER-OWNED and ALLOW-LISTED — the runtime never invents one. Per-grid allow-lists (both grids identical in this slice): `submittedAt_desc` (default) + `submittedAt_asc`. Sortable column for both grids: ONLY `submittedAt`. **Demo-submissions Sort VO** is the package-side `Semitexa\PlatformUi\Domain\Model\Event\UiFormDemoSubmissionSort` — same allow-list shape as the project-side lead Sort VO, with `invalid_sort` rejection routed through the package-side `UiFormDemoSubmissionSearchException`. Out-of-scope this slice: contact-field / lead_message / `id_*` sorting, multi-sort, client-side sort. **The shared `UiGridFilterState` envelope is UNCHANGED** (Option B): sort travels as a hidden form input, not as part of the on-wire filter envelope.

  - **Runtime**: `src/Application/Static/js/grid-runtime.js`, declared in the package `assets.json` (`scope: global`, `position: body`, `priority: 70`, `defer: true`). Namespace: `window.SemitexaUi.grid` (`bootAll()` is the explicit re-discovery hook; auto-runs on `DOMContentLoaded`). Reads the inline `<script type="application/json" data-ui-grid-bundle>` block for column order + refresh-marker name + page-fallback URL.

  - **Runtime safety**: DOM mutations use `createElement` + `textContent` exclusively. Cell attributes go through `setAttribute` with a literal allow-list (`style`, `ui-text`, `data-ui-grid-row-id`); the per-column `style` string is sourced from the server-rendered bundle, NOT from the JSON envelope. Row keys filtered through the bundle's `columns` allow-list — extra keys ignored. JSON envelope shape-checked before any DOM update; deviation surfaces an error banner. **No `innerHTML`, no `eval`, no `Function` constructor, no `document.write`, no script-tag rendering, no arbitrary selectors from server payload, no client-side dataset cache.** Pinned by `GridRuntimeStaticAssertTest`.

  - **Generic SSE refresh** (per-grid configurable). The runtime listens for `semitexa:ui-sse:patch-applied` CustomEvents and reloads when `patch.target.name === <refreshMarker>` AND `patch.target.instance === <grid-root-instance-id>`. The marker name is read from the grid root's `data-ui-grid-refresh-marker` attribute, so each grid can use its own marker namespace without runtime changes.

  - **Lead listing migration** (`src/modules/UiPlayground/src/Application/View/templates/pages/lead-admin.html.twig`): now invokes `component('platform.grid', {gridId, instanceId, dataUrl, sseUrl, refreshMarker: 'lead-grid-refresh-marker', pageFallbackUrl, emptyMessage, columns: […], initialRows, initialPagination, initialQuery, initialAction}, {filters: <form>, filterState: <p>, footer: <p>})`. The lead admin handler still owns the SSE channel mint + topic subscribe + bundle push; the grid component template just renders the shell.

  - **Project-side runtime removed**. The previous `src/modules/UiPlayground/src/Application/Static/js/lead-grid-runtime.js` + its `assets.json` are gone — the package runtime covers it via the configurable refresh-marker name. Project module no longer needs an asset manifest.

  - **Demo-submissions migration** (`src/modules/UiPlayground/src/Application/View/templates/pages/demo-submissions-admin.html.twig`): the OK branch invokes `component('platform.grid', {gridId: 'platform-ui.demo-submissions', dataUrl: '/ui-playground/admin/demo-submissions/grid-data', sseUrl: null, pageFallbackUrl, emptyMessage, columns: [submittedAt (sortable), id, actionName, contactName, contactTopic, contactMessagePreview], initialRows: submissions, initialPagination, initialQuery, initialAction, initialSort, sortParam}, {filters: <form (incl. hidden sort input)>, filterState: <p>})`. JSON data endpoint at `GET /ui-playground/admin/demo-submissions/grid-data` (`DemoSubmissionsAdminGridDataPayload` + `DemoSubmissionsAdminGridDataHandler`) reuses the package-side `UiFormDemoSubmissionListCriteria`, `UiFormDemoSubmissionCursor`, `UiFormDemoSubmissionSort`, `UiFormDatabaseDemoSubmissionRepositoryInterface`, and `UiDemoSubmissionAdminAuthorizerInterface` verbatim — same 5-gate ordering, same envelope shape as the lead grid-data endpoint. Accepts `?sort=` with the allow-listed tokens (`submittedAt_desc` default, `submittedAt_asc`); unknown tokens → `400` with `reason: invalid_sort` and NO repository read. The cursor's filter fingerprint binds the active sort token so cross-sort cursor reuse → `400 invalid_cursor`. **No SSE for demo-submissions in this slice**: `sseUrl: null` on the grid root → the package runtime renders the grid dynamically (filter + sort + Next) but skips SSE attach; the demo listing is refresh-on-action, not live-refresh.
  - **Grid id namespacing**: `platform-ui.demo-submissions` (the underlying records are package-owned demo data) vs. `ui-playground.leads` (project-owned business data). The grid id is opaque to the runtime; the convention helps operators correlate the two grids in mixed deployments.

  - **Shared envelope contract** (`Semitexa\PlatformUi\Domain\Model\Grid\UiGridDataResponse`): both grid-data handlers shape their JSON envelopes through one tiny static factory:

    ```php
    UiGridDataResponse::success(
        gridId:     'platform-ui.demo-submissions',
        rows:       $rows,                                 // already projected by the handler
        pagination: new UiGridPaginationData($page->limit, $page->hasMore, $page->nextCursor?->encode()),
        filters:    new UiGridFilterState($criteria->query, $criteria->actionName),
    );
    UiGridDataResponse::error('invalid_cursor', 'Pagination cursor is invalid.');
    ```

    The helper owns ONLY the on-wire envelope shape (key list + key order pinned by `UiGridDataResponseTest`). It does NOT authorize, query, parse criteria, decode cursors, project rows, or pick HTTP statuses — every one of those concerns stays in the handler. The `UiGridPaginationData` + `UiGridFilterState` DTOs are pure data carriers (no validation, no normalisation); handlers feed already-canonical values. A future grid-data endpoint that wants to participate in the `platform.grid` contract MUST go through `UiGridDataResponse` so the on-wire envelope cannot drift across consumers. **This is an envelope contract, not a generic grid-data-provider framework** — there is no shared repository, no shared criteria, no shared SSE policy; those decisions stay with each consumer.

  > **Superseded.** The original project-side SSE refresh plumbing below was
  > built against the now-retired platform-ui patch-stream subsystem
  > (channel-token + per-channel patch publisher). With all UI streaming unified
  > on the canonical KISS stream, that plumbing is superseded; the description is
  > retained as historical design context only.

  The original SSE refresh plumbing (topic registry, publisher wrapper, store-action call) was:

  - **JSON data endpoint** at `GET /ui-playground/admin/leads/grid-data` (`LeadAdminGridDataPayload` + `LeadAdminGridDataHandler`). Returns the safe envelope `{ok, gridId: "ui-playground.leads", rows[...], pagination: {limit, hasMore, nextCursor}, filters: {q, action}}` — the envelope key list + key order is byte-identical to the demo-submissions grid (Option B: sort travels as a query parameter / form input, not in the response). Accepts `?sort=` with the allow-listed tokens (`submittedAt_desc` default, `submittedAt_asc`); unknown tokens → `400` with `reason: invalid_sort` and NO repository read. The cursor's filter fingerprint binds the active sort token, so a cursor minted under one sort cannot be re-used under another (`400 invalid_cursor`). Same 5-gate ordering as the page handler (authorize → criteria → cursor → fingerprint → repo). Error envelope on any failure: `{ok: false, reason, message}` with 400/403 status. **Never carries `values_json`, tokens, ctx, dispatchId, debug, or class FQCNs** (pinned by a canary integration test).

  - **Topic registry** for SSE refresh — the retired framework patch publisher was strictly point-to-point (per-channel Redis LIST), so the project introduced a small subscription layer: `UiPlaygroundLeadGridRefreshTopicInterface` (cache-backed default + in-memory test fallback) holds a map `{channelId → (instanceId, expiresAt)}` under namespace `ui-playground-lead-grid-refresh`. The lead admin page handler subscribed its freshly minted SSE channel + grid root instance id on each render (TTL 600s). Stale entries are pruned on every read; the single-map storage shape is bounded by the cache key's namespace TTL.

  - **Refresh publisher** (`UiPlaygroundLeadGridRefreshPublisherInterface`, default `DefaultUiPlaygroundLeadGridRefreshPublisher` — `#[SatisfiesServiceContract]`, container-managed, the framework patch publisher property-injected). After `UiPlaygroundStoreLeadAction::handle()` saves a row, the action calls `publishRefresh()` which iterates the topic's subscribers and publishes a `setText` patch to each: `targetName: 'lead-grid-refresh-marker'`, `value: (string) time()`. Patch fan-out is best-effort — both the publisher and the action wrap the publish call in `try {…} catch (\Throwable)` so a stale channel id, dead Redis connection, or contract-violating custom publisher cannot un-do the save.

  - **Refresh-marker patch shape**. Fixed: `op=setText`, `targetInstance=<grid-root-instance-id>`, `targetName='lead-grid-refresh-marker'`, `value='<unix-ts>'`. **Never carries lead values, leadId, or operator-internal jargon** (pinned by `refresh_signal_carries_no_lead_values` — the publisher's contract method is parameterless, so lead-value data has nowhere to flow through it).

  - **Pagination footer UX** (added after the original RC; ships in both grids because the component is shared):
    - **Previous button** (`<button data-ui-grid-prev>`). Hidden on first paint; the runtime reveals it whenever the client-side cursor-history stack grows past page 1. No server-side fallback href — cursors are forward-only and the server doesn't keep history; on the no-JS path users still see only the Next link, identical to the original RC behavior.
    - **Visited-page numbered buttons** (`<span data-ui-grid-pages>` populated with `<button data-ui-grid-page="N">` one per visible visited page). The runtime renders buttons only for pages whose cursor it has actually observed (page 1 is the implicit `null` cursor) AND only within the configured sliding window (see **Sliding window** below). Clicking page `N` re-uses the stored cursor for that page; there is no jump-to-arbitrary-page because the cursor model has no total-count or `page=N` support.
    - **Sliding window** (configurable). Caller prop: `paginationWindowSize` (default `7`, clamped server-side AND client-side to `[1, 25]`; non-numeric / missing values fall back to `7`). Emitted onto the grid root as `data-ui-grid-pagination-window-size="<N>"`. The runtime's pure helper `computePageWindow(currentPage, knownPages, windowSize)` (exposed on `window.SemitexaUi.grid` for console debugging / future Node-side tests) returns the inclusive `[start, end]` page range to render. The window centers the active page when possible, slides toward the start near page 1, and toward the end near `knownPages`. It NEVER extends past `state.cursors.length` — fabricating buttons for unknown future pages would offer cursors we don't have. Examples (with `windowSize=5`, `knownPages=20`): `page 1 → 1..5`, `page 4 → 2..6`, `page 8 → 6..10`, `page 20 → 16..20`. When `knownPages < windowSize`, the window simply shrinks to fit (page 1 of 3 known → `1..3`). When `knownPages = 0` (the empty-state branch the table-wrap already hides), the window is `{0, 0}` and the pagination footer collapses.
    - **Ellipsis markers** (`<span aria-hidden="true">…</span>`) appear on either side of the visible window when there are visited pages outside it. They are deliberately NOT buttons and do NOT carry `data-ui-grid-page` — the click delegator looks for `closest('[data-ui-grid-page]')`, so an ellipsis can never resolve to a navigation. Their only role is the visual hint "more known history exists" — they're decorative.
    - **Current-page indicator** (`<span data-ui-grid-page-indicator>`). Updated via `textContent` on every reload — `Page <N>`, suffixed with `· more available` when `pagination.hasMore` is true. **Never** displayed as `Page N of M` because `M` is unknown.
    - **State preservation**: Previous / numbered-page navigation preserves `q`, `action`, `sort`, and `limit` unchanged. The Next link continues to do the same.
    - **Reset triggers**: any filter-form submit, any opt-in `data-ui-grid-reload-on-change` change (typically the page-size select), and any sort-header click invoke `resetPaginationHistory()` — clears `state.cursor`, resets `state.cursors` to `[null]`, and sets `state.page = 1`. A cross-criteria cursor would either be rejected by the server-side fingerprint guard (for `q` / `action` / `sort` changes) or land mid-stream (for `limit` changes); the early reset means the UI never offers a misleading Previous / numbered button that would behave inconsistently.
    - **Cursor security unchanged**: the existing `UiFormDemoSubmissionCursor` / `UiPlaygroundLeadSubmissionCursor` shape regex + filter-fingerprint binding still gate every server-side cursor request. Stored client-side cursors are opaque to the runtime — they're treated as strings and pushed onto / popped off the history stack without inspection. Server-side rejection ( `400 invalid_cursor`) is preserved end-to-end.
    - **Safe DOM**: every button + indicator update goes through `createElement` + `textContent` + `setAttribute` only. No `innerHTML`, no `eval`, no `Function` constructor, no `document.write` — pinned by `GridRuntimeStaticAssertTest`.

  **Explicit non-goals** for the grid (each is a separate future slice): row selection, inline edit, bulk actions, export, virtual scroll, client-side dataset cache, client-side query engine, **arbitrary jump-to-page** (page=N), **total-count display** (would require an extra repo call per request and a contract change), multi-column sort, client-side sort, `id_*` / `lead_message` / `contactName_*` / `contactTopic_*` / `contactMessage_*` sort tokens, demo-submissions SSE refresh. Column sort UI for both grids (lead + demo-submissions, `submittedAt_*` only on each) has shipped in two parity-matched slices — see the **Sortable column headers** bullet above for the contract. Previous-button + visited-page-button + current-page-indicator pagination has shipped (see **Pagination footer UX** above); arbitrary jump-to-page remains deferred.

**Demo submission repository** (`UiFormDemoSubmissionRepositoryInterface`):

```php
interface UiFormDemoSubmissionRepositoryInterface
{
    public function save(UiFormDemoSubmissionRecord $record): string;       // returns id verbatim
    public function find(string $id): ?UiFormDemoSubmissionRecord;          // test + safe diagnostic
    public function isShared(): bool;
    public function diagnosticName(): string;
}
```

Default impl: **`CacheBackedUiFormDemoSubmissionRepository`** (`#[SatisfiesServiceContract]`), namespace `ui-form-demo-submissions`, **24h TTL** (`CacheBackedUiFormDemoSubmissionRepository::TTL_SECONDS = 86400`). Lazy-default fallback: `InMemoryUiFormDemoSubmissionRepository` (worker-local; used in tests and single-worker dev). Worker-scoped static holder: `UiFormDemoSubmissionRepository::{getActive, setActive, reset}`, populated by `BootPlatformUiRegistryListener` mirroring the rule / action / authorizer / policy / CSRF-store pattern.

**Database-backed sibling** (`UiFormDatabaseDemoSubmissionRepositoryInterface`):

```php
interface UiFormDatabaseDemoSubmissionRepositoryInterface
{
    public function save(UiFormDemoSubmissionRecord $record): string;
    public function find(string $id): ?UiFormDemoSubmissionRecord;
    public function isShared(): bool;
    public function diagnosticName(): string;
}
```

Same shape as the cache variant — separate interface so the cache-backed action and the database-backed action stay strictly orthogonal (neither one silently re-targets the other). Production default: **`UiFormDemoSubmissionDbRepository`** (`#[SatisfiesRepositoryContract]`, ORM-managed via `OrmManager`). Lazy-default fallback: `InMemoryUiFormDatabaseDemoSubmissionRepository` (worker-local, used in tests). Worker-scoped static holder: `UiFormDatabaseDemoSubmissionRepository::{getActive, setActive, reset}`.

**Table**: `platform_ui_demo_submissions`. Columns:

| Column             | Type                        | Notes                                                                  |
|---|---|---|
| `id`               | `VARCHAR(32)` PK manual     | `uifs_<16hex>` — caller-supplied                                       |
| `form_instance_id` | `VARCHAR(80)`               | `uci_<…>` of the rendered form                                         |
| `action_name`      | `VARCHAR(128)`              | `platform.demo.storeContactDb`                                         |
| `submitted_at`     | `DATETIME`                  | server-stamped at insert                                               |
| `values_json`      | `LONGTEXT`                  | `json_encode` of the action's allow-listed values map (deterministic)  |
| `created_at`       | `DATETIME`                  | from `HasTimestamps` trait                                             |
| `updated_at`       | `DATETIME`                  | from `HasTimestamps` trait                                             |

Schema is kept in sync by `bin/semitexa orm:sync` (run as part of `bin/semitexa update`). The table is registered through the standard `#[FromTable]` + `#[Column]` attributes on `UiFormDemoSubmissionResource`; no manual SQL migrations.

The same `UiFormDemoSubmissionRecord` readonly value object is exchanged at the public interface boundary — both repositories implement `save(Record)` + `find(id): ?Record` so dispatch tests can assert exact stored shapes regardless of which sink received the row.

### Read-only diagnostic listing (`/ui-playground/admin/demo-submissions`)

The `UiFormDatabaseDemoSubmissionRepositoryInterface` exposes two bounded read-only listing methods:

```php
public function recent(int $limit = self::DEFAULT_RECENT_LIMIT): array;
public function paginate(
    ?UiFormDemoSubmissionCursor $cursor = null,
    int $limit = self::DEFAULT_RECENT_LIMIT,
): UiFormDemoSubmissionPage;
// DEFAULT_RECENT_LIMIT = 25, MAX_RECENT_LIMIT = 100
```

Implementations clamp `$limit` to `[1, MAX_RECENT_LIMIT]` and return rows newest-first (`ORDER BY submitted_at DESC, id DESC` — the `id` tie-breaker keeps the ordering deterministic when multiple rows share the same `submitted_at` second). `recent($n)` is the simple "first page only" surface, equivalent to `paginate(null, $n)->records`. No `findAll()`, no filter-by-action, no date-range scan, no search — those are explicit future work.

**Keyset pagination**. `paginate()` advances via an opaque cursor — never an offset. Implementations fetch `$limit + 1` rows to detect `hasMore`, then trim. The returned `UiFormDemoSubmissionPage` carries `{records, nextCursor, limit, hasMore}`:

```php
final readonly class UiFormDemoSubmissionPage
{
    /** @var list<UiFormDemoSubmissionRecord> */
    public array $records;
    public ?UiFormDemoSubmissionCursor $nextCursor;
    public int  $limit;
    public bool $hasMore;
}
```

`nextCursor` is set when `hasMore && records !== []`, pointing at the LAST returned record so the next page can resume strictly after it under the compound predicate `(submitted_at, id) < (cursor.submittedAt, cursor.id)`.

**Cursor shape**. `UiFormDemoSubmissionCursor` is opaque to callers — its only public surface is `encode(): string` (the wire form) and `decode(string): self` / `tryFromString(?string): ?self`. The encoded cursor is `base64url(JSON {s: submittedAt, i: id})` with no padding, no signing, no operator-internal fields. Decode is strict: base64url alphabet only, `base64_decode(..., true)`, `json_decode(depth: 4, JSON_THROW_ON_ERROR)`, tight key whitelist (`['i', 's']` only), `int`/`string` type checks, and the `id` regex (`/\Auifs_[a-f0-9]{16}\z/`) — any deviation throws `UiFormDemoSubmissionCursorException(reasonCode: 'invalid_cursor')`. The codec never calls `unserialize` or `eval`. Exception messages are a fixed string — they never echo the bad cursor back.

The cursor carries no secrets — only an id and a timestamp that are already visible on the listing page. No signing is required because (a) the cursor reveals nothing the listing doesn't already reveal, and (b) the strict shape validation rejects every malformed input at parse time before any repository read happens.

This drives a tiny dev-facing diagnostic page registered in the **UiPlayground module** at `GET /ui-playground/admin/demo-submissions`. The page shows the most recent rows from `platform_ui_demo_submissions` with the same safety guarantees the rest of the submit pipeline maintains:

- Twig autoescapes every value (`<script>alert(1)</script>` renders as literal text).
- Message previews are server-truncated to 160 characters (single `…` ellipsis appended).
- The view-model is a flat array (`{id, actionName, formInstanceId, submittedAt, contactName, contactTopic, contactMessagePreview, storedFieldCount}`). Raw `values_json` never reaches the template.
- The page deliberately does NOT surface CSRF token ids, raw tokens, signed-ctx blobs, dispatchIds, raw payload bytes, or debug internals — they are not in the table to begin with, and the projection never invents them.

**Access control**: `UiDemoSubmissionAdminAuthorizerInterface` (throw-on-deny, mirrors the action authorizer / security policy seams). Default impl is `ConfigurableUiDemoSubmissionAdminAuthorizer` (`#[SatisfiesServiceContract]`) — deny-by-default unless `PLATFORM_UI_DEMO_ADMIN_ENABLED` is explicitly truthy. Dev playground deployments that intentionally want open diagnostics can bind `AllowAllUiDemoSubmissionAdminAuthorizer` themselves or install it through the worker-scoped static holder. Worker-scoped static holder + Boot listener wiring match the surrounding patterns. Denial returns HTTP 403 with a safe template state — never the bad caller's identity, never class FQCNs.

**Protected mode (default built-in)**: the package ships the env-gated authorizer as the default:

```php
#[SatisfiesServiceContract(of: UiDemoSubmissionAdminAuthorizerInterface::class)]
final class ConfigurableUiDemoSubmissionAdminAuthorizer
    implements UiDemoSubmissionAdminAuthorizerInterface
{
    public const ENV_FLAG = 'PLATFORM_UI_DEMO_ADMIN_ENABLED';
    // ...
}
```

Apps that need a different decision can replace the default either via their own boot listener call —

```php
UiDemoSubmissionAdminAuthorizer::setActive(
    new AllowAllUiDemoSubmissionAdminAuthorizer(), // dev-only open diagnostics
);
```

— or by binding their own implementation through `#[SatisfiesServiceContract(of: UiDemoSubmissionAdminAuthorizerInterface::class)]` (e.g. a permission-based authorizer composed with the configurable one).

Flag matrix (case-insensitive after trim): `1` / `true` / `yes` / `on` / `enabled` → allow. Anything else (unset, empty, `0`, `false`, `off`, `disabled`, random text, whitespace) → deny with `UiDemoSubmissionAdminAuthorizationException(reasonCode: 'demo_admin_disabled')`. The message is a fixed string — it never echoes the env value, the flag name, or any class FQCN back. Pinned by `ConfigurableUiDemoSubmissionAdminAuthorizerTest`.

Denial paths converge on the same handler behaviour: HTTP 403, repository **NOT** read, no rows rendered, safe denial copy + reason code in the template. The reason code distinguishes the cause:

| Reason code           | Source                                                          |
|---|---|
| `demo_admin_forbidden` | default `UiDemoSubmissionAdminAuthorizationException()`         |
| `demo_admin_disabled`  | `ConfigurableUiDemoSubmissionAdminAuthorizer` — env flag absent or falsey |
| `role_required` / any  | custom application authorizer                                   |

**Diagnostic listing query parameters**:

| Param | Default | Behaviour |
|---|---|---|
| `limit` | `25` (`DEFAULT_RECENT_LIMIT`) | Clamped server-side to `[1, MAX_RECENT_LIMIT]` (`MAX_RECENT_LIMIT = 100`). Non-numeric / empty / negative / whitespace falls back to the default. `0` clamps up to `1`. Both the handler and the repository clamp — the handler value is surfaced to the template so the rendered "page size" matches what the user receives. |
| `cursor` | _(absent)_ | Opaque base64url token returned by the previous page's `nextCursor`. Empty / missing → first page. Malformed → HTTP 400 with the safe template state (no submissions rendered, repository never read). Cursor binds to the filter combination it was issued under (see *Cursor / filter binding* below). |
| `q` | _(absent)_ | Bounded diagnostic-grade search term (max `100` UTF-8 characters; longer → HTTP 400 `invalid_search_query`). Trimmed; empty / whitespace → treated as absent. Case-insensitive substring match against the allow-listed contact fields (`contact_name`, `contact_topic`, `contact_message`). Not a full-text engine. |
| `action` | _(absent)_ | Optional allow-listed action filter. The only accepted value today is `platform.demo.storeContactDb` — the listing surfaces DB-backed rows only. Any other value → HTTP 400 `invalid_action_filter` and the bad value is never echoed back. |

**Gate ordering inside the handler** (security-significant):

1. **Authorization** runs FIRST. A denied caller never sees a 400 for malformed q / action / cursor input (no decode oracle on the deny path). The repository is never read.
2. **Search criteria** (`q` + `action` + `limit`) are parsed and validated next. Oversize `q` → 400 `invalid_search_query`. Unknown `action` → 400 `invalid_action_filter`. No raw bad input is echoed back; the form re-renders empty.
3. **Cursor decode** runs only after criteria validation passes. Malformed cursor → 400 `invalid_cursor`. Still no repository read.
4. **Cursor / filter binding**: the cursor's optional `filterFingerprint` MUST equal the criteria's `fingerprint()`. Mismatch → 400 `invalid_cursor`. A cursor issued under a filtered listing is not reusable as an unfiltered cursor and vice-versa.
5. **Repository read** runs only after every gate passes — `paginate()` when the criteria is unfiltered, `searchPage()` otherwise.

**Search semantics & SQL safety**.

The ORM impl binds the search term via a parameterised LIKE against the serialised `values_json` column:

```sql
WHERE values_json LIKE ? ESCAPE '\\'
```

User-supplied `%` and `_` characters are escaped before binding so a literal `%` in the search term cannot turn into a wildcard. The escape character `\` is itself escaped first so a trailing `\` in the user input cannot escape the closing `%` of the bound pattern. The search term is NEVER concatenated into SQL — it travels as a `?` placeholder value. The `action_name` filter binds via the typed `where(Operator::Equals)` helper, not raw SQL.

This is **diagnostic-grade** search:

- It substring-matches against the entire JSON-serialised values blob — there's no per-field index.
- It scans up to `limit + 1` rows, never the whole table.
- It does NOT support phrase queries, stemming, ranking, or fuzzy matching. Use a real search engine for any of those.

**Cursor / filter binding (filter fingerprint)**.

The cursor's wire format gained an optional `f` key:

```jsonc
// unfiltered listing                 // filtered listing (q="alpha")
{"s": 1778900000, "i": "uifs_…"}      {"s": 1778900000, "i": "uifs_…", "f": "<16 hex>"}
```

`f` is the first 16 hex characters of `sha256(query|action)` over the canonical case-folded form (`mb_strtolower(trim(q ?? ''))` + `|` + `action ?? ''`). It is a tamper-resistance prefix, not a secret — knowing `f` doesn't help an attacker because the listing is already public-by-route under the active authorizer.

Acceptance rules:

- A v1 (2-key) cursor decodes as `filterFingerprint=null`. It is accepted only when the active criteria is unfiltered (`f === null`).
- A v2 (3-key) cursor with `f=X` is accepted only when the active criteria's `fingerprint()` equals `X`.
- Any other combination → HTTP 400 `invalid_cursor`. The repository is never read.

This makes "splice a cursor from listing A onto listing B" impossible — an operator who wanted to walk a filtered keyset under a different filter would have to forge a matching fingerprint, which means knowing the canonical form of the new filter's `(q, action)` tuple.

The `Next page →` link in the template preserves the active `q` / `action` / `limit` alongside the encoded cursor so following pagination keeps the same filter context end-to-end.

**Privacy guarantees** (unchanged across this slice):

- The repository's `save()` path still only stores the four documented columns (`id` / `form_instance_id` / `action_name` / `submitted_at` / `values_json`) — no tokens, no signed-ctx blob, no dispatchId, no debug, no payload bytes.
- The handler's projection still emits only `{id, actionName, formInstanceId, submittedAt, contactName, contactTopic, contactMessagePreview, storedFieldCount}`. Raw `values_json` is never surfaced.
- The bad-search / bad-cursor states render a safe banner with a stable reason code; the bad user input is never echoed back.
- Twig autoescape still wraps every value at render time — even an `<script>alert(1)</script>` in `contact_name`, `contact_message`, or the `q` parameter renders as literal text, never markup.

**What this slice does NOT implement** (explicit non-goals): per-field search (`field`), date-range query, per-user view, edit, delete, export, soft-delete, undo, RBAC matrix, admin UI for the cache-backed repository, list endpoint for the `find()` method beyond this one route, "Previous page" link / backward keyset, jump-to-page / total-count display, persistent cursor history, full-text engine, ranking / scoring, search-as-you-type.

**Stored record shape** (`UiFormDemoSubmissionRecord`):

```php
final readonly class UiFormDemoSubmissionRecord {
    public string $id;              // 'uifs_<16hex>' generated by the action
    public string $formInstanceId;  // 'uci_<…>' of the rendered form
    public string $actionName;      // 'platform.demo.storeContact'
    public int    $submittedAt;     // Unix timestamp
    public array  $values;          // allow-listed sanitised values only
}
```

The record carries no tokens, no signed-ctx blob, no dispatchId, no request payload, no debug internals. The repository is a dumb sink — sanitisation lives in the action.

**Safety gate ordering before storage** (canonical pipeline):

1. signed-ctx HMAC verification;
2. dispatchId replay claim;
3. dispatcher-level `UiInteractionAuthorizerInterface`;
4. authoritative server-side field validation;
5. `UiFormSubmitActionRegistryInterface` resolves the action by signed name;
6. `UiFormSubmitActionAuthorizerInterface` allows the attempt;
7. `UiFormSubmitSecurityPolicyInterface` verifies + **consumes** the one-time CSRF token.

Only after all seven pass does the action's `handle()` run. Invalid submits / replay / authz denial / CSRF failures never reach storage — pinned by the dispatch test matrix (one record per valid submit; zero records after any gate fails).

**Demo-grade limitations**:

- The default `platform.demo.storeContact` action uses the cache-backed demo repository with a 24-hour TTL — records evaporate; abandoned demo deployments do not accumulate data.
- The alternate `platform.demo.storeContactDb` action uses the DB-backed demo repository/table (`platform_ui_demo_submissions`) and persists rows until the consuming app's database retention policy removes them. Operators can override that behaviour by binding `UiFormDatabaseDemoSubmissionRepositoryInterface` or replacing the DB action wiring.
- The action does NOT send email, redirect, call external APIs, create accounts, or run any other business action.
- Real persistence (audit, retention, queryability) is a separate slice with its own storage contract.

### Submit action authorizer + security policy seams

The action seam is gated by two dedicated seams that run AFTER authoritative field validation and BEFORE the action's `handle()`:

1. **`UiFormSubmitActionAuthorizerInterface::authorize(UiFormSubmitActionAuthorizationContext)`** — application-level identity / role / rate-limit decision. Default `AllowAllUiFormSubmitActionAuthorizer` is a no-op so the demo flow works unchanged.
2. **`UiFormSubmitSecurityPolicyInterface::verify(UiFormSubmitSecurityContext)`** — submit-shaped CSRF / session / token check. Default is now **`CacheBackedUiFormSubmitSecurityPolicy`** — a one-time nonce bound to the rendered form via the signed ctx (`cfg.s = {k, t}`) and an HMAC-stored cache entry. See "Submit CSRF policy" below for the full token flow. `SignedContextOnlyUiFormSubmitSecurityPolicy` stays available as an explicit opt-in fallback for environments without a shared cache or for tests that want to bypass CSRF.

Both seams use a **throw-on-deny** convention (the dispatcher-level `UiInteractionAuthorizerInterface` returns bool because it has no need for a per-decision reason channel — these seams do, so they raise typed exceptions instead):

```php
interface UiFormSubmitActionAuthorizerInterface
{
    /** @throws UiFormSubmitActionAuthorizationException on deny. */
    public function authorize(UiFormSubmitActionAuthorizationContext $context): void;
}

interface UiFormSubmitSecurityPolicyInterface
{
    /** @throws UiFormSubmitSecurityPolicyException on policy failure. */
    public function verify(UiFormSubmitSecurityContext $context): void;
}
```

Both exceptions carry a `reasonCode` (`role_required`, `rate_limited`, `csrf_verification_failed`, `session_required`, `submit_security_failed`, …) plus a user-facing `message`. FormComponent catches them and emits **the same two form-level patches** as a normal action (`setText form-status` + `setAttribute ui-state=invalid`) — no class names, no raw values, no patches outside the existing allow-list. Debug surface:

```jsonc
"action": {
  "name":    "platform.demo.accept",
  "invoked": false,
  "reason":  "action_forbidden",       // or "submit_security_failed"
  "detail":  "role_required",          // the exception's reasonCode
  "message": "You do not have permission to run this action."
}
```

**Override seam**: apps bind their own implementations via `#[SatisfiesServiceContract(of: ...)]` in a module that "extends" semitexa-platform-ui. The contract registry picks the descendant-module winner; `BootPlatformUiRegistryListener` stashes them in `UiFormSubmitActionAuthorizer` / `UiFormSubmitSecurityPolicy` (worker-scoped static holders, mirroring the rule-registry pattern).

### Submit CSRF policy (nonce-backed, one-time consume)

The default security policy is **`CacheBackedUiFormSubmitSecurityPolicy`**. It binds a one-time token to each rendered form-with-action:

1. **Render time.** Form template calls `ui_form_issue_submit_csrf($actionName)` (only when `submitAction` is set). The helper asks the active `UiFormSubmitCsrfTokenStoreInterface` to mint a fresh `{id, raw}` pair. The store keeps ONLY `hash_hmac('sha256', raw, id)` against `id` in a namespaced cache (`ui-form-submit-csrf`), with a TTL bounded by the form ctx lifetime (default 600 s / 10 min). The pair is signed into `cfg.s = {k: <id>, t: <raw>}` of the submit ctx.

2. **Dispatch time.** FormComponent reads `event->config['s']` and passes it as the new `UiFormSubmitSecurityContext::$securityConfig` field to the policy. The policy:
   - asserts `cfg.s.k` matches `uicsrf_[a-f0-9]{16}` and `cfg.s.t` matches `[a-f0-9]{32}`;
   - calls `UiFormSubmitCsrfTokenStoreInterface::consume($k, $t)` which atomically verifies HMAC + removes the entry;
   - returns void on success, throws `UiFormSubmitSecurityPolicyException(reasonCode: 'csrf_verification_failed', message: 'Submit security check failed. Please reload the form and try again.')` on any failure (missing / expired / wrong / already consumed — all collapse to the same surface, no side-channel).

3. **One-time consume semantics.** The policy runs AFTER field validation + the action authorizer. So:
   - **invalid submits** never reach `consume()` → the token survives → the user can fix the form and resubmit;
   - **authorizer-denied submits** never reach `consume()` → token survives;
   - **valid + authorized submits** consume the token regardless of whether the action itself rejects (acceptable — the user already saw a server response, which is enough to invalidate the bearer secret).
   - A second submit attempt with the same `cfg.s` after a successful first one fails CSRF — the user reloads the form to mint a fresh token. The playground demo exercises this end-to-end.

**Token store**: `UiFormSubmitCsrfTokenStoreInterface` (`issue($ttl) → UiFormSubmitCsrfTokenHandle{id, raw}` + `consume($id, $rawToken): bool` + `isShared(): bool` + `diagnosticName(): string`). Default impl is `CacheBackedUiFormSubmitCsrfTokenStore` (`#[SatisfiesServiceContract]`, namespaced through `CacheManagerInterface`, observable across all workers sharing the cache backend). Lazy-default fallback is `InMemoryUiFormSubmitCsrfTokenStore` for tests / single-worker dev (NOT safe across Swoole workers).

**Trust perimeter**:
- Cache stores ONLY the HMAC hash. A leaked cache snapshot cannot replay a token because the raw value is never persisted and HMAC keys each hash with the token id.
- Failure messages never echo the bad token id or value.
- `cfg.s` carries only `{k, t}`: no session id, no cache key format details, no class FQCNs, no service ids.
- `payload.csrf` / `payload.csrfToken` / `payload.csrf_token` (top-level + form-nested) remain forbidden by `UiPayloadFieldGuard` from the previous slice — the client cannot smuggle the token through anywhere except the signed ctx, where the HMAC binds it.

**Known limitations** (call-outs in `primitives.md` limitations list):
- This is a **nonce-backed**, **not yet session-bound** policy. The token is bound to the rendered form via the signed ctx; it is not bound to a session cookie. A leaked full-page HTML (with the signed ctx + token) can be submitted from any UA until consumed. True session binding lands when Semitexa exposes a stable request-scoped seam reachable from reflection-instantiated components.
- No CSRF token rotation across multiple forms on the same page — each `FormComponent` instance mints its own independent token.
- TTL is a fixed default (600 s) at the helper level; future work threads a configurable TTL through.

**Override seam**: apps that want a stricter policy bind their own implementation with `#[SatisfiesServiceContract(of: UiFormSubmitSecurityPolicyInterface::class)]` (and optionally their own token store). The `BootPlatformUiRegistryListener` stashes the container-bound winners in the matching static holders.

**Submit ordering** (single canonical pipeline):

1. parse signed `cfg.f`;
2. parse signed `cfg.a` (optional);
3. validate every signed field → `UiFormSubmitResult`;
4. **if invalid**: emit per-field + summary patches; authorizer / policy / action NEVER run; `debug.action.reason = 'validation_invalid'`;
5. **if valid && cfg.a is set**:
   a. resolve action via registry;
   b. run authorizer → may throw `UiFormSubmitActionAuthorizationException` → safe denial patches + `debug.action.reason = 'action_forbidden'`;
   c. run security policy → may throw `UiFormSubmitSecurityPolicyException` → safe denial patches + `debug.action.reason = 'submit_security_failed'`;
   d. invoke action's `handle()` → form-status uses the action's message + state.
6. **if valid && no cfg.a**: emit per-field + summary patches with the standard "Form is valid. Submit accepted." message.

Patch order is fully stable: per-field first, form-level last, action extras (if any) appended after. Denials NEVER produce action extras.

**Payload guard** rejects the corresponding smuggling attempts: `payload.action`, `payload.submitAction`, `payload.csrf`, `payload.csrfToken`, `payload.security`, `payload.policy`, `payload.authorization`, `payload.authz` (and case / separator variants) all return `400 forbidden_payload_field`. Single-letter `a` is intentionally NOT in the forbidden list because legitimate form field names could collide; the signed `cfg.a` is the only canonical channel.

**Trust perimeter** (auto vs. manual is identical):

- Field definitions are **server-rendered**. The collector stores PHP value objects (`UiFormSubmitFieldDefinition`); no client payload, no DOM scanning at submit time.
- Rules are normalised through the active `UiFieldRuleRegistry` at render time. Unknown rule names / malformed params fail in the template, not at dispatch.
- The collector defensively re-runs the wire validation through `UiFormSubmitConfigParser::parseSignedWire()` before signing, so duplicate field names / duplicate instance ids / unsafe instance ids cannot reach `cfg.f`.
- No raw values, no class names, no service / method names enter the metadata.

**Rendered shape**:

```html
<div data-ui-component="platform.form"
     data-ui-component-instance-id="uci_..."
     data-ui-form-aggregate="1"
     ui-component="form"
     role="group">
  <h2>title</h2>
  <p>description</p>
  <form data-ui-part="form" action="#" novalidate>
    <div data-ui-form-fields>...content slot...</div>
    <div data-ui-patch-target="form-status">...</div>
    <div data-ui-form-submit-row>
      <button data-ui-primitive="platform.button" type="submit" ui-tone="brand">Validate form</button>
    </div>
  </form>
  <script type="application/json" data-ui-event-manifest="...">{"v":1,"c":"platform.form","i":"uci_...","events":[{"p":"form","e":"submit","ctx":"sc1.…"}]}</script>
</div>
```

**Signed submit ctx — claim shape**:

```jsonc
{
  "c": "platform.form",
  "i": "uci_<form-instance>",
  "p": "form",
  "e": "submit",
  "cfg": {
    "f": [
      {"n":"access_code","r":[{"n":"required"},{"n":"minLength","p":[4]}],"l":"Access code","q":true},
      {"n":"confirm_access_code","r":[{"n":"required"},{"n":"sameAsField","p":["access_code","Codes must match."]}],"l":"Confirm access code","q":true}
    ]
  },
  "iat": ..., "exp": ...
}
```

Each field entry uses the compact single-letter wire shape: `n` (name), `r` (rules wire), `l` (optional label), `q` (optional required flag).

**Wire payload (submit dispatch)**:

```jsonc
{
  "ctx": "sc1.…",                    // signed form-submit ctx
  "dispatchId": "ui_evt_<hex>",
  "payload": {
    "value": null,                   // form-level events have no single value
    "form": {                        // sanitised by UiFormPayloadSnapshot
      "values": {
        "access_code":         "abcd",
        "confirm_access_code": "abcd"
      }
    }
  }
}
```

Smuggling attempts (`payload.rules`, `payload.cfg`, `payload.form.rules`, `payload.form.cfg`, any routing-flavored key) are rejected with `400 forbidden_payload_field` by the existing `UiPayloadFieldGuard`.

**Authoritative final validation flow**:

1. `UiInteractionDispatcher` verifies the submit ctx, resolves the handler through `UiComponentRegistry::get('platform.form')->event('form','submit')`, and instantiates `FormComponent`.
2. `UiFormPayloadSnapshot::extract($payload)` produces the sanitised `formValues` map, which the dispatcher attaches to the event.
3. `FormComponent::onSubmit` reads `$event->config['f']` (the signed field list) through `UiFormSubmitConfigParser::parseSignedWire`. The list is the **authoritative** input to validation — client values feed it, the client cannot change it.
4. For every signed field: the handler instantiates the rule chain via `UiFieldRuleParser::resolveFromWire`, builds a `UiFieldValidationContext` carrying the full `formValues` (so cross-field rules like `sameAsField` see siblings), and runs `UiFieldValidator`.
5. The handler aggregates `{name, state, message}` per field into `UiFormSubmitResult::fromFieldResults(...)`.
6. The result projects to **two patches only** — `setText` on `form-status`, `setAttribute` `ui-state` on the form root.

**Result + status messages** (verbatim contract, pinned by tests):

| Case | Message |
|---|---|
| `totalCount === 0` (no signed fields) | `Form has no fields.` |
| All fields valid | `Form is valid. Submit accepted.` |
| 1 field invalid | `1 field needs attention.` |
| N fields invalid | `<N> fields need attention.` |

**Debug surface** — safe-to-log shape, never echoes submitted values:

```jsonc
"debug": {
  "instance": "uci_...",
  "submit": {
    "valid":        false,
    "totalCount":   2,
    "validCount":   1,
    "invalidCount": 1,
    "fields": [
      {"name":"access_code",         "state":"valid",   "message":"Looks good."},
      {"name":"confirm_access_code", "state":"invalid", "message":"Codes must match."}
    ],
    "message": "1 field needs attention."
  },
  "form": {
    "snapshotFields": ["access_code", "confirm_access_code"],
    "snapshotSize":   2
  }
}
```

**Frontend submit capture**:

`event-runtime.js` extends its existing event delegation:

- The native `submit` event on an element matching `data-ui-part="form"` is captured (capture phase), and `ev.preventDefault()` is called **exactly there** — no other native event has its default suppressed. The single guarded `preventDefault` callsite is pinned by `EventRuntimeAssetTest`.
- `collectFormValuesSnapshot` now handles both cases: captured instance IS the form root (submit dispatch), or captured instance is a field inside a form root (input-change dispatch). Walks the same `[data-ui-form-aggregate="1"][data-ui-component-instance-id]` ancestor query in both directions.
- The wire body for submit is the same `{ctx, dispatchId, payload}` envelope every other dispatch uses; `payload.value` is `null` (the form element has no `.value`), `payload.form.values` carries the snapshot.

**Security / trust boundary**:

- Submit is **authoritative** within the demo: the response distinguishes accepted from rejected based on signed rules + sanitised values.
- Submit does **NOT** trust client-side aggregate state, validation messages, or any boolean the client claims. Counts and per-field outcomes come from running the server-owned rule chain.
- Submit does **NOT** persist anything. Persistence in a future slice must add authorization, CSRF/session policy if relevant, and storage-specific validation on top of this seam.
- Submit response **never echoes submitted values** — only counts + per-field state/message + the snapshot field-key set.
- The signed `cfg.f` shape, the parser, the result projection, and the patch allow-list are all the same trust perimeter the rest of the validation stack uses.

**Limitations of this slice**:

- No persistence. No business action. No redirect. No real account creation / email send.
- Automatic slot introspection now resolves field definitions for FieldComponents inside the content slot (`autoFields: true`). Fields rendered outside the slot, or anonymous / unsafe-named FieldComponents, are not discovered — caller must use the manual `fields` prop instead. No multi-pass component tree reflection.
- Submit action seam, action authorizer seam, CSRF/security policy seam, and the first persistent demo action are all in place. Built-in defaults are `platform.demo.accept` (no-op), `platform.demo.storeContact` (cache-backed demo storage), `AllowAllUiFormSubmitActionAuthorizer`, `CacheBackedUiFormSubmitSecurityPolicy`, and `CacheBackedUiFormDemoSubmissionRepository`. Real persistent business actions (DB-backed, audit-aware, with bespoke authorization) are an explicit future slice.
- CSRF policy is in place via `CacheBackedUiFormSubmitSecurityPolicy` (one-time nonce, HMAC-stored in a namespaced cache, bound to the rendered form via `cfg.s`). It is **nonce-backed, not session-bound** — a token issued for a rendered form survives across user agents until consumed. Full session binding lands when Semitexa exposes a request-scoped seam reachable from reflection-instantiated components.
- No redirect / file upload / async / database action variants in `UiFormSubmitActionResult`.
- No request metadata (session id, auth identity, IP) in the authorization / security contexts yet — a separate slice once Semitexa lands a stable convention for passing it into UI handlers.
- No async / database / remote validation.
- No multi-step forms, no durable form state, no cross-tab state sync.
- No new patch op, no new attribute allow-list entry. `disabled` is still off the allow-list — submit button cannot be disabled through a patch in this slice.

## Per-field submit projection (signed `cfg.f.i`)

Submit now emits **per-field validation patches** in addition to the form-level summary. Each signed field definition can carry an `instanceId` matching `UiInstanceIdGenerator::SAFE_ID_PATTERN` (`uci_[A-Za-z0-9_-]{1,64}`). When `cfg.f[*].i` is present, the handler projects that field's `UiFieldValidationResult::toPatches($i)` output — the SAME shape `FieldComponent::onInputChanged` already emits (aria-invalid + ui-state + validation-message). Patches are ordered per-field first, form-level last, so the visible form-status reflects the aggregate after every field DOM has been updated.

**Caller API** (extends the existing submit demo):

```twig
{% set _field_defs = [
    {
        name: 'access_code',
        instanceId: 'uci_submit_access_code',     # signed into cfg.f.i
        label: 'Access code',
        required: true,
        rules: ['required', ['minLength', 4]],
    },
    {
        name: 'confirm_access_code',
        instanceId: 'uci_submit_confirm_access_code',
        label: 'Confirm access code',
        required: true,
        rules: ['required', ['sameAsField', 'access_code', 'Codes must match.']],
    },
] %}

{% set _fields %}
    {{ component('platform.field', {
        name: 'access_code',
        instanceId: 'uci_submit_access_code',     # SAME id on field render
        showValidationTarget: true,
        rules: ['required', ['minLength', 4]],
    }) }}
    {{ component('platform.field', {
        name: 'confirm_access_code',
        instanceId: 'uci_submit_confirm_access_code',
        showValidationTarget: true,
        rules: ['required', ['sameAsField', 'access_code', 'Codes must match.']],
    }) }}
{% endset %}

{{ component('platform.form', {
    fields: _field_defs,
    showSubmit: true,
    submitText: 'Validate form',
}, { content: _fields }) }}
```

**FieldComponent `instanceId` prop**:

The `instanceId` prop is optional. When absent, the field generates a fresh `uci_<16hex>` id (unchanged behaviour). When present and matching `UiInstanceIdGenerator::SAFE_ID_PATTERN`, the field uses it consistently:

- on `data-ui-component-instance-id` (root attribute),
- in the signed event manifest's `i` claim (so the input-change ctx pins to the same id),
- in `data-ui-event-manifest`.

A render-time `ui_component_instance_for(override)` Twig helper handles the override + safe-id validation. An unsafe override raises `UiComponentRegistryException` at render time — developer mistakes surface immediately as a Twig error rather than at dispatch.

**Wire shape change** (additive — `i` is optional):

```jsonc
"cfg": {
  "f": [
    {
      "n": "access_code",
      "i": "uci_submit_access_code",                 // NEW (optional)
      "r": [{"n":"required"},{"n":"minLength","p":[4]}],
      "l": "Access code",
      "q": true
    },
    {
      "n": "confirm_access_code",
      "i": "uci_submit_confirm_access_code",         // NEW (optional)
      "r": [{"n":"required"},{"n":"sameAsField","p":["access_code","Codes must match."]}],
      "l": "Confirm access code",
      "q": true
    }
  ]
}
```

Old submit ctxs (rendered before this slice) keep working: when `cfg.f[*].i` is absent the handler skips per-field projection and emits only the form-level summary.

**Patch allow-list — instance-id authority**:

`UiPatchValidator` now accepts an optional `additionalAllowedInstances: list<string>` argument. `UiInteractionDispatcher` walks the verified `cfg` claim, collects every value matching `UiInstanceIdGenerator::SAFE_ID_PATTERN`, and passes the resulting set to the validator alongside the primary signed instance. The rule is: a handler may patch ANY instance that itself survived HMAC verification.

- The walk is generic (no FormComponent-specific knowledge in the dispatcher) and produces no false positives because the safe-id regex (`uci_` + alphanumerics/underscores/hyphens, bounded) is a tight subset of arbitrary strings.
- A patch targeting any unsigned instance still returns `422 patch_instance_mismatch`.
- The client cannot retarget patches through the payload — the payload has no instance-id surface, and `payload.form.values` is sanitised to safe identifier keys → scalar values only.
- Tampering `cfg.f[*].i` invalidates the signed ctx → `403 invalid_signed_ctx`.

**Result + patches** (per-field invalid example):

```jsonc
{
  "patches": [
    // access_code (valid)
    {"op":"setAttribute","target":{"instance":"uci_submit_access_code","part":"input"},"attribute":"aria-invalid","value":null},
    {"op":"setAttribute","target":{"instance":"uci_submit_access_code","part":"input"},"attribute":"ui-state","value":"valid"},
    {"op":"setText","target":{"instance":"uci_submit_access_code","name":"validation-message"},"value":"Looks good."},
    // confirm_access_code (invalid — sameAsField mismatch)
    {"op":"setAttribute","target":{"instance":"uci_submit_confirm_access_code","part":"input"},"attribute":"aria-invalid","value":"true"},
    {"op":"setAttribute","target":{"instance":"uci_submit_confirm_access_code","part":"input"},"attribute":"ui-state","value":"invalid"},
    {"op":"setText","target":{"instance":"uci_submit_confirm_access_code","name":"validation-message"},"value":"Codes must match."},
    // form-level summary LAST
    {"op":"setText","target":{"instance":"uci_form_...","name":"form-status"},"value":"1 field needs attention."},
    {"op":"setAttribute","target":{"instance":"uci_form_..."},"attribute":"ui-state","value":"invalid"}
  ],
  "debug": {
    "instance": "uci_form_...",
    "submit": {
      "valid": false, "totalCount": 2, "validCount": 1, "invalidCount": 1,
      "fields": [
        {"name":"access_code","state":"valid","message":"Looks good."},
        {"name":"confirm_access_code","state":"invalid","message":"Codes must match."}
      ],
      "message": "1 field needs attention.",
      "projectedFieldInstances": ["uci_submit_access_code","uci_submit_confirm_access_code"]
    },
    "form": {"snapshotFields": ["access_code","confirm_access_code"], "snapshotSize": 2}
  }
}
```

**Limitations of this slice**:

- Per-field projection is **opt-in** — when a field def lacks `instanceId`, only the form-level summary is emitted for that field. Backward-compatible by design.
- With `autoFields: true`, slotted FieldComponents auto-register their instance id into cfg.f.i — no caller-side `instanceId` plumbing required. The manual `fields` path still supports an explicit `instanceId` for bespoke wiring; both share the same trust perimeter.
- No new patch op, no new attribute allow-list entry. `disabled` remains off the allow-list — submit button cannot be disabled mid-flight.
- No persistence, no business action, no redirect, no CSRF / session policy.
- No multi-step forms, no async validation, no durable form state.
- Frontend runtime is unchanged. The patches go through the same safe applier the field input-change path already uses.

## Playground

`/ui-playground/components/field` now walks through thirteen scenarios: **Event metadata (declaration-only)** table, **Signed event manifest (inert JSON)**, **Frontend capture (no backend dispatch)** with a live capture log, **Backend dispatch — response patches** with a dispatch + patch lifecycle log and a visible server-ack target updated by a `setText` patch, **Server push — SSE patches** with Connect / Push / Disconnect buttons that exercise the full publish→subscribe→apply path, **Server validation — response patches** with `showValidationTarget: true` and live aria-invalid / ui-state / validation-message updates as the user types, bind, basic field, help, error, disabled, slots (prefix/suffix with primitives), `inputProps` caller overrides, and a multi-field fake form layout. The backend-dispatch, SSE, and validation demos are the only places on the playground that opt the transport bridges in and update visible patch targets; regular pages do not POST, subscribe, or validate automatically.

`/ui-playground/components/form` shows the **minimal FormComponent aggregation** slice end-to-end: three `FieldComponent`s inside a single `platform.form`, each with its own signed rule list. As the user types, every change triggers a dispatch; the runtime updates the field's own validation patches and then refreshes the form-level `form-status` text + `ui-state` attribute via two synthesised patches through the existing safe applier. The page exposes the live `forms.snapshot()` data and a dispatch log so the aggregate transition (`pending` → `invalid` → `valid`) is visible without DevTools. A second section, **Authoritative submit validation — per-field projection**, demonstrates the submit pipeline with two `access_code` fields wired with stable `instanceId`s (`uci_submit_access_code` / `uci_submit_confirm_access_code`) threaded into both the `fields` prop and each `platform.field` render. Pressing **Validate form** dispatches the signed submit ctx; the server runs every rule against the submitted snapshot and returns per-field patches (aria-invalid + ui-state + validation-message) AND the form-level summary — same patch shape, no persistence.
