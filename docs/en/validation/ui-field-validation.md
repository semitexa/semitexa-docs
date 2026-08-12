---
id: validation/ui-field-validation
section: validation
slug: ui-field-validation
title: UI Field Validation
summary: The server-side rules DSL for platform-ui fields, including cross-field rules such as sameAsField.
order: 20
locale: en
status: canonical
keywords:
  - field validation
  - rules DSL
  - sameAsField
  - cross-field
---

# UI Field Validation

## Field validation (server-side rules DSL)

`FieldComponent` validates its value through a small server-side rules DSL on the `input.change` event. Rules are declared per-instance via the `rules` prop, normalized at render time, **signed into the event manifest's ctx claim** as `cfg.r`, and read back from the verified ctx by the handler. The client cannot change rules through the request payload — the field guard rejects `payload.rules` / `payload.r` / `payload.cfg` / `payload.config`.

**Rule spec DSL** (developer-facing, in `rules` prop):

```php
component('platform.field', {
    label: 'Username',
    name: 'username',
    required: true,
    showValidationTarget: true,
    rules: [
        'required',           // parameterless rule: string form
        ['minLength', 3],     // parametrized rule: [name, params…]
        ['maxLength', 20],
    ],
})
```

**Built-in rules** (default registry; apps can register more — see "Custom rule registry" below):

| Rule name | Params | Behaviour | Message on failure |
|---|---|---|---|
| `required` | — | Rejects empty / whitespace-only. | `This field is required.` |
| `minLength` | int min ≥ 0 | Trims, compares `mb_strlen` ≥ min. **Empty values pass** — pair with `required`. | `Please enter at least {min} characters.` |
| `maxLength` | int max ≥ 0 | Compares `mb_strlen` ≤ max. Does NOT trim. Empty passes. | `Please enter no more than {max} characters.` |
| `sameAsField` | string `siblingFieldName`, optional string `customMessage` | Cross-field comparator. Compares the current trimmed scalar value against `formValue(siblingFieldName)`. Both empty → pass. Current non-empty + sibling missing → `Please complete the related field first.` (sentinel, not overridable). Mismatch → `customMessage` if provided, else `Values must match.`. See "Cross-field validation" below. | `Values must match.` (default) |

**Ordering**: first-failure-wins. The first rule whose `validate()` returns a non-null result short-circuits the pipeline. When all rules pass the validator returns a valid result with the configured success message (default: `Looks good.`).

**Rule interface** — `Semitexa\PlatformUi\Application\Service\Validation\UiFieldValidationRuleInterface`:

```php
interface UiFieldValidationRuleInterface
{
    public function validate(mixed $value, UiFieldValidationContext $context): ?UiFieldValidationResult;
}
```

Implementations MUST be pure: no IO, no globals, no Twig, no services. The validator (`UiFieldValidator::validate(value, rules, context)`) is stateless and runs sync — async / cross-field rules are future-work.

**Responsibility split** (this slice formalised the seam):

- **Parser** (`UiFieldRuleParser`) — security perimeter for the DSL surface. Rejects closures, callables, service names, class FQCNs, non-scalar parameters, non-list outer shape, non-string rule names. **Does NOT validate rule names against the registry** (that's a registry concern). `parseAll()` returns specs after the structural checks; `parseAllToWire()` additionally invokes the registry to validate names + params at render time before emitting the wire shape. The constructor requires a `UiFieldRuleRegistryInterface` explicitly — there is no silent fallback to `DefaultUiFieldRuleRegistry`, so a caller wired against the wrong registry fails loudly instead of validating against the wrong rule set.
- **Registry** (`UiFieldRuleRegistryInterface`) — single source of truth for which rule names exist, what their parameters look like, and which concrete class implements each. The default (`DefaultUiFieldRuleRegistry`) owns the three built-ins. Apps replace the registry via `SatisfiesServiceContract` to add their own rules.
- **Rule object** (`UiFieldValidationRuleInterface`) — pure value-level check, returns `null` for pass / `UiFieldValidationResult::invalid(...)` for fail.

Malformed specs throw `UiFieldValidationRuleException` at render time so misconfigurations surface in dev as a clear Twig error. At dispatch time the handler maps the exception to a safe `422 invalid_validation_rule` response without leaking class FQCNs.

**Custom rule registry** (this slice). Apps add their own rules by binding a custom `UiFieldRuleRegistryInterface` implementation:

```php
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\PlatformUi\Application\Service\Validation\DefaultUiFieldRuleRegistry;
use Semitexa\PlatformUi\Application\Service\Validation\UiFieldRuleRegistryInterface;

#[SatisfiesServiceContract(of: UiFieldRuleRegistryInterface::class)]
final class AppFieldRuleRegistry implements UiFieldRuleRegistryInterface
{
    private DefaultUiFieldRuleRegistry $builtins;

    public function __construct()
    {
        // Compose with the default to inherit required / minLength / maxLength.
        $this->builtins = new DefaultUiFieldRuleRegistry();
    }

    public function resolve(UiFieldRuleSpec $spec): UiFieldValidationRuleInterface
    {
        return match ($spec->name) {
            'slug'   => new SlugRule(),
            'domain' => new DomainRule(),
            default  => $this->builtins->resolve($spec),
        };
    }

    public function knownRuleNames(): array
    {
        return [...$this->builtins->knownRuleNames(), 'slug', 'domain'];
    }
}
```

Custom rule names are signed into `cfg.r` exactly like built-ins — the wire shape is shape-agnostic about which names a registry knows about. The security perimeter contract is unchanged: **never** instantiate a class derived from `$spec->name` via reflection / `class_exists` / service lookup. Use a fixed `match` (or equivalent allow-list) so an attacker can't smuggle FQCNs through a rule name.

**Override granularity (this slice — full registry replacement only)**: apps register one registry that wins under the contract registry's module-order rule. Multi-provider rule contribution (several modules each adding their own rules without coordinating) is future work — track it under "Future runtime steps".

**FieldComponent integration** (DI-resolved as of this slice). The container-bound `UiFieldRuleRegistryInterface` is now plumbed through every real runtime path:

- **Boot**: `BootPlatformUiRegistryListener` is instantiated by the container with the container-bound winner of `UiFieldRuleRegistryInterface`. On `WorkerStartAfterContainer` the listener calls `UiFieldRuleRegistry::setActive($registry)`, stashing the active registry in a worker-scoped static holder (same pattern as `UiPrimitiveRegistry` / `UiComponentRegistry`).
- **Render time**: the `ui_field_rules()` Twig helper instantiates `UiFieldRuleParser` with `UiFieldRuleRegistry::getActive()`. Custom rule names from a bound registry now pass through `rules:` props at template compile time.
- **Dispatch time**: `UiDispatchHandler` injects `UiFieldRuleRegistryInterface` via `#[InjectAsReadonly]` and passes it to `UiInteractionDispatcher` (new optional `ruleRegistry` ctor arg). After the dispatcher instantiates a component, it checks `instanceof UsesUiFieldRuleRegistry` and calls `withFieldRuleRegistry($activeRegistry)`. `FieldComponent` implements that interface — its `onInputChanged()` resolves the wire-shape rules through the registry the dispatcher provided.

**The `UsesUiFieldRuleRegistry` interface** (opt-in bridge):

```php
interface UsesUiFieldRuleRegistry
{
    public function withFieldRuleRegistry(UiFieldRuleRegistryInterface $registry): static;
}
```

Why a setter-style bridge and not constructor injection: `FieldComponent` is still instantiated outside the DI container (by `UiInteractionDispatcher::instantiate()` via reflection's `newInstance()`). Documented in the package boundary audit (gray area G1). Once Semitexa lands container-managed component instances, the bridge can drop in favour of `#[InjectAsReadonly]` directly — the public interface keeps the same name. Components that don't need validation rules don't implement the interface; the dispatcher's `instanceof` check skips them.

**Standalone fallback**: code paths that bypass bootstrap (unit tests constructing `FieldComponent` directly, or `FieldComponent::validate(string)` called for ad-hoc validation) lazily-default to a fresh `DefaultUiFieldRuleRegistry` via `UiFieldRuleRegistry::getActive()`. The behaviour matches "production with no custom binding" — only built-ins resolve.

**Test seam**: `UiFieldRuleRegistry::setActive($custom)` overrides the active registry without going through the container; `reset()` restores the lazy-default. Use this to drive end-to-end paths (render-time `ui_field_rules` + dispatch-time `FieldComponent`) with a custom rule set in tests. See `tests/Integration/UiFieldRuleRegistryWiringTest.php` for the full end-to-end pattern.

**Signed ctx**: the event manifest now carries an optional `cfg` claim (server-trusted per-event configuration). `FieldComponent`'s template computes the wire spec via `ui_field_rules(rules)` and threads it into `ui_event_manifest()`:

```twig
{%- set _wire = ui_field_rules(rules|default([])) -%}
{%- set _cfg = _wire is empty ? {} : {'input.change': {'r': _wire}} -%}
{{ ui_event_manifest(_ui_instance, null, _cfg) }}
```

The dispatcher's HMAC verification authenticates the rules; tampering with `cfg.r` breaks the signature and surfaces as `403 invalid_signed_ctx`. The handler reads the verified rules via `UiInteractionEvent::rules()`.

**Backward compatibility**: a FieldComponent rendered without the `rules` prop falls back to `FieldComponent::DEFAULT_RULES` (`['required', ['minLength', 3]]`), preserving the demo behaviour the previous slice shipped. Existing tests, manifests built before this slice (without `cfg.r`), and external callers that don't know about rules all continue to work.

**Result type** — `Semitexa\PlatformUi\Domain\Model\Event\UiFieldValidationResult`:

```php
final readonly class UiFieldValidationResult
{
    public const STATE_VALID = 'valid';
    public const STATE_INVALID = 'invalid';
    public const VALIDATION_TARGET_NAME = 'validation-message';

    public string  $state;     // 'valid' | 'invalid'
    public ?string $message;

    public static function valid(?string $message = null): self;
    public static function invalid(string $message): self; // message MUST be non-empty
    public function isValid(): bool;
    public function toPatches(string $instanceId): array;  // list<UiResponsePatch>
}
```

**Result type** — `Semitexa\PlatformUi\Domain\Model\Event\UiFieldValidationResult`:

```php
final readonly class UiFieldValidationResult
{
    public const STATE_VALID = 'valid';
    public const STATE_INVALID = 'invalid';
    public const VALIDATION_TARGET_NAME = 'validation-message';

    public string  $state;     // 'valid' | 'invalid'
    public ?string $message;

    public static function valid(?string $message = null): self;
    public static function invalid(string $message): self; // message MUST be non-empty
    public function isValid(): bool;
    public function toPatches(string $instanceId): array;  // list<UiResponsePatch>
}
```

`toPatches()` emits the existing `UiResponsePatch` shape — no new operations, no new attributes, no new targets. For an **invalid** result:

1. `setAttribute` on the `input` UiPart: `attribute=aria-invalid`, `value='true'`.
2. `setAttribute` on the `input` UiPart: `attribute=ui-state`, `value='invalid'`.
3. `setText` on the `validation-message` named target: the diagnostic message.

For a **valid** result the first patch sets `value=null` (the applier calls `removeAttribute` for `null`), the second sets `ui-state=valid`, and the third sets the positive message. A valid result with `message=null` skips the third patch entirely.

**Render-time opt-in**: set `showValidationTarget: true` on `FieldComponent` to render `<span data-ui-patch-target="validation-message" id="<field-id>-validation" aria-live="polite">`. When this prop is set, the input's server-rendered `aria-describedby` is automatically extended to include the validation-message id (space-separated list per the HTML spec) so screen readers announce the diagnostic alongside the field's existing help/error text.

**Handler contract**: `FieldComponent::onInputChanged()` reads the signed rule list from `$event->rules()`, resolves them via `UiFieldRuleParser::resolveFromWire()`, runs them through `UiFieldValidator`, calls `toPatches()` against `$event->instanceId`, and appends the legacy `server-ack` setText (preserved so the older "Backend dispatch" demo keeps working). The dispatcher's `UiPatchValidator` accepts every patch because each one pins to `$event->instanceId`, uses an allow-listed op, and uses an allow-listed attribute name.

**What this slice does NOT introduce**: a form engine, form submission, multi-field rules, async validation, schema validation, persistence, a client-side mirror, a custom-rule registry (apps can only use the three built-ins for now), or any new patch operation. Future-work items list how those layers can sit on top of this seam.

## Cross-field validation (`sameAsField`)

`sameAsField` is the first cross-field built-in. It compares the current field's value against a sibling field's value read from a sanitised client-submitted snapshot.

```php
component('platform.field', {
    label: 'Access code',
    name: 'access_code',
    rules: ['required', ['minLength', 4]],
    showValidationTarget: true,
})

component('platform.field', {
    label: 'Confirm access code',
    name: 'confirm_access_code',
    rules: [
        'required',
        ['sameAsField', 'access_code', 'Codes must match.'],
    ],
    showValidationTarget: true,
})
```

**Behaviour**:

- Comparison is on the trimmed scalar projection of each side. `int 1` and `string "1"` compare equal — JSON transport may stringify scalars.
- Both sides trim to empty → pass. Pair with `required` if empties should fail.
- Current non-empty + sibling missing from the snapshot → `Please complete the related field first.` (sentinel diagnostic; custom message does NOT override this case).
- Values differ → `customMessage` if provided, else `Values must match.`.
- Sibling name must match the safe identifier shape `[A-Za-z_][A-Za-z0-9_-]*` — the registry rejects bad shapes at resolve time.

**Wire shape (`payload.form.values` snapshot)**:

```jsonc
{
  "ctx": "sc1.…",
  "dispatchId": "ui_evt_…",
  "payload": {
    "value": "abcd",                                  // current field value
    "form": {                                         // NEW, optional
      "values": {                                     // NEW
        "access_code": "abcd",
        "confirm_access_code": "abcd"
      }
    }
  }
}
```

Sanitised by `UiFormPayloadSnapshot` at the dispatch boundary:

- Keys must match `[A-Za-z_][A-Za-z0-9_-]*` (same shape `data-ui-field-name` uses).
- Values must be scalar or `null`. Arrays / objects → `400 invalid_form_snapshot_value`.
- At most **50** keys per snapshot → `400 form_snapshot_too_large`.
- Each scalar value bounded to **4096** characters (mb-length) → `400 form_snapshot_value_too_long`.
- `payload.form.rules` / `payload.form.cfg` / any other routing-flavored key anywhere in the payload tree → `400 forbidden_payload_field` (existing `UiPayloadFieldGuard` recursive scan).

**Field-name policy note**: because `UiPayloadFieldGuard` walks the entire payload tree and rejects routing-flavored keys (`event`, `handler`, `method`, `class`, `component`, `instance`, `part`, `updates`, `endpoint`, `url`, `route`, `action`, `controller`, `callback`, `dispatcher`, `payloadclass`, `authzscope`, `backendhandler`, `patch`, `patches`, `target`, `selector`, `html`, `script`, `dispatchid`, `requestid`, `eventid`, `rules`, `r`, `cfg`, `config`), a developer-defined form field named with one of those tokens (case-insensitive, hyphen/underscore-insensitive) is rejected even inside `payload.form.values`. This is the "safest" policy from the slice spec — the small UX cost (rename one field) buys uniform protection against future smuggling shapes.

**Signed rule config**:

- The rule spec list, including the sibling field name, is signed into `cfg.r` at render time. The client CANNOT change which field a rule targets — tampering breaks the HMAC.
- The current field's safe `name` prop is additionally signed into `cfg.fn`. The handler self-merges `$event->value()` into `$event->formValues[$fn]` so self-referencing rules behave predictably AND so a frontend that forgot to include the current field still sees the canonical "current" value.
- `payload.rules` / `payload.r` / `payload.cfg` are rejected with `400 forbidden_payload_field` by the existing guard.

**Validation context**:

`UiFieldValidationContext` now carries a `formValues` map (sanitised). Rules read sibling values through `$context->formValue('siblingName')`. The map is **untrusted UX-feedback input** — never authoritative state.

**Trust boundary — re-read with every change**:

1. **Rules are signed.** They cannot be added, removed, or retargeted by the client.
2. **Snapshot values are client-submitted.** A user could lie about the sibling value to silence a validation message. `sameAsField` (and any future cross-field rule) is therefore **UX-feedback only**.
3. **Final persistence is out of scope for this slice.** When the real submit pipeline lands, it MUST revalidate the whole submitted payload against authoritative state (server-rendered fields, persistent form state, or fresh queries) before touching the database. The cross-field result returned by `/__ui/dispatch` is *not* a green light to persist.

**Debug surface**:

Dispatch responses surface the SHAPE of the snapshot in `debug.form.{snapshotFields, snapshotSize}` — key list + count. **Values never appear in `debug`** so operator logs stay uniform regardless of field sensitivity.

**Sensitive value handling**:

The existing dispatch handler still emits a `server-ack` setText patch with the dispatched value (preserved from the original dispatch demo). That makes `sameAsField` unsafe to use with password-shaped fields out of the box — the playground demo uses generic `access_code` / `confirm_access_code` fields instead. A future slice may introduce per-field "sensitive" metadata that suppresses the ack echo; this slice deliberately does not because the broader sensitive-field problem (logs, debug surfaces, error messages) needs its own design pass.

**Limitations of this slice**:

- One cross-field rule (`sameAsField`). No `greaterThan`, `lessThan`, `requiredIf`, `compareDate`, etc. — they are obvious follow-ups but kept out to keep the trust-boundary discussion focused.
- No async / database / remote validation.
- No multi-step forms, no real submit, no persistence, no CSRF.
- No client-side rule mirror. `UiFieldValidator` stays server-only.
- No cross-tab state sync.
