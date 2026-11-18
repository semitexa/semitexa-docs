---
id: validation/core-validators
section: validation
slug: core-validators
title: Core Validators
summary: Trait-based validators for presence, type, format, numbers, dates, choices, collections and domain rules, run automatically before handlers.
order: 10
locale: en
status: published
verified_against: 2026.09.19.1020
keywords:
  - ValidatablePayloadInterface
  - PresenceValidationTrait
  - StringValidationTrait
  - FormatValidationTrait
  - CollectionValidationTrait
  - ConditionalValidationTrait
  - CompositeValidationTrait
  - validation envelope
  - HandlesInvalidPayloadInterface
  - HTML form validation
  - invalid()
  - nested validation paths
  - "Path::join"
---

# Core Validators

Semitexa ships a dependency-free, trait-based validation surface. Drop a category trait into a Payload DTO, return an error map from `validate()`, and the framework answers 422 before any handler's `handle()` runs.

**The 422 is JSON unless the handler says otherwise.** A browser that posts an HTML form to such a payload gets the raw `{"errors": {...}}` body on screen. To show the form again with its errors and the values the visitor typed, the route's handler implements `HandlesInvalidPayloadInterface::invalid()`: see [An HTML form: show it again with its errors](#an-html-form-show-it-again-with-its-errors).

## Philosophy

- **Validation lives on the payload.** `handle()` receives a validated payload; it does not re-validate, and it does not return 422 itself. The one thing a handler may add is `invalid()`, which only *presents* errors the payload already found.
- **Errors are aggregated, not thrown.** Every category-trait validator appends to an `array<string, list<string>> $errors` accumulator so a single request reports every problem at once.
- **Validators are composable.** Every method shares the same signature shape, so composite rules (`anyOf`, `oneOf`, `sequentially`) and conditional rules (`requiredIf`, `validateSometimes`) take callbacks that match every other validator.
- **No metadata graph.** A validator is a method on a trait. There is no constraint registry, no annotation engine, no Symfony Validator dependency. The traits are the API.

## Method convention

Every accumulator validator has the same shape:

```php
validateXxx(array &$errors, string $field, mixed $value, ...$options): void
```

- `&$errors` — the accumulator. Validators append `['field' => ['message']]`.
- `$field` — the error key (a flat string; nested keys come from `Path::join`).
- `$value` — the value being validated (typed wider where a stricter type would prevent reuse from generic callers).
- `...$options` — validator-specific (length bound, threshold, callback, …).

Setter-time validators (the legacy `NotBlankValidationTrait::requireNotBlank`) keep their original throw-immediately shape; both styles coexist in the same payload without conflict.

## Null handling

Most validators silently accept `null`. That lets callers chain a `validateOptional()` guard without re-checking inside each rule:

```php
if ($this->validateOptional($errors, 'website', $this->website)) {
    $this->validateUrl($errors, 'website', $this->website);
}
```

Exceptions:

- Presence validators (`validateRequired`, `validateNotBlank`, `validateNotNull`) treat `null` as a meaningful absence.
- Equality validators (`validateEqualTo`, `validateIdenticalTo`) compare `null` like any other value — `validateEqualTo($v, null)` succeeds only when `$v === null`.
- Conditional validators (`validateRequiredIf`, `validateProhibitedIf`) inspect blankness explicitly.

## Validation flow

1. The HTTP request reaches `PayloadAdmission::gateAndHydrate()` (called by `RouteExecutor`), after the pre-hydration auth gate.
2. `PayloadHydrator::hydrate()` invokes a setter for every key the request carries; setter-time `ValidationException` becomes 422 before `validate()` runs. A field the request does not carry never reaches its setter, so "required" can only be checked in `validate()`.
3. If the payload implements `ValidatablePayloadInterface`, its `validate()` runs after hydration.
4. A non-empty error map turns into a 422 response with envelope:

```json
{
  "errors": {
    "name":  ["This value should not be blank."],
    "email": ["This value should be a valid email address."]
  }
}
```

5. The payload reaches `handle()` only when `validate()` returned `[]`.
6. The envelope above is what a JSON client gets, and what a browser gets too, unless the request prefers HTML and the route's handler implements `HandlesInvalidPayloadInterface`. Then `invalid()` runs in place of `handle()`: see [An HTML form: show it again with its errors](#an-html-form-show-it-again-with-its-errors).

## Nested error paths

The error envelope keeps its flat `array<string, list<string>>` shape. Nested errors come from composing keys with `Path::join`:

```php
use Semitexa\Core\Validation\Path;

Path::join('items', 0, 'sku');     // 'items[0].sku'
Path::join('address', 'country');  // 'address.country'
Path::join('tags', 1);             // 'tags[1]'
```

`CollectionValidationTrait::validateArrayOf` and `validateMapOf` use `Path::join` automatically, so a per-item callback that emits an error under `$field` ends up at `field[0]`, `field[1]`, etc.

## A public payload — contact form

```php
namespace Semitexa\Modules\ValidationDemo\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Contract\ValidatablePayloadInterface;
use Semitexa\Core\Validation\Trait\CollectionValidationTrait;
use Semitexa\Core\Validation\Trait\FormatValidationTrait;
use Semitexa\Core\Validation\Trait\PresenceValidationTrait;
use Semitexa\Core\Validation\Trait\StringValidationTrait;
use Semitexa\Core\Validation\Trait\ChoiceValidationTrait;
use Semitexa\Modules\ValidationDemo\Application\Resource\Response\ValidationAcceptedResource;

#[AsPublicPayload(
    path: '/validation-demo/contact',
    methods: ['POST'],
    responseWith: ValidationAcceptedResource::class,
)]
final class ContactFormPayload implements ValidatablePayloadInterface
{
    use PresenceValidationTrait;
    use StringValidationTrait;
    use FormatValidationTrait;
    use ChoiceValidationTrait;
    use CollectionValidationTrait;

    private string $name = '';
    private string $email = '';
    private string $message = '';
    private ?string $website = null;
    private ?array $tags = null;

    public function setName(string $value): void    { $this->name = $value; }
    public function setEmail(string $value): void   { $this->email = $value; }
    public function setMessage(string $value): void { $this->message = $value; }
    public function setWebsite(?string $value): void { $this->website = $value; }
    public function setTags(?array $value): void    { $this->tags = $value; }

    public function validate(): array
    {
        $errors = [];

        $this->validateNotBlank($errors, 'name', $this->name);
        $this->validateLength($errors, 'name', $this->name, min: 2, max: 100);

        $this->validateNotBlank($errors, 'email', $this->email);
        $this->validateRfcEmail($errors, 'email', $this->email);

        $this->validateNotBlank($errors, 'message', $this->message);
        $this->validateMaxLength($errors, 'message', $this->message, 2000);

        if ($this->validateOptional($errors, 'website', $this->website)) {
            $this->validateUrl($errors, 'website', $this->website);
        }

        if ($this->validateOptional($errors, 'tags', $this->tags)) {
            $this->validateMaxCount($errors, 'tags', $this->tags, 10);
            $this->validateArrayOf(
                $errors,
                'tags',
                $this->tags,
                static function (array &$itemErrors, string $itemField, mixed $tag): void {
                    if (! is_string($tag)) {
                        $itemErrors[$itemField] = $itemErrors[$itemField] ?? [];
                        $itemErrors[$itemField][] = 'This value should be a string.';
                    }
                },
            );
        }

        return $errors;
    }
}
```

A non-string entry inside `tags` produces `tags[1]: ["This value should be a string."]`, demonstrating nested paths through `validateArrayOf`.

## An HTML form: show it again with its errors

**The trap:** `validate()` alone is an API answer. A browser form posted to the `ContactFormPayload` above shows the visitor a page of raw JSON, because the 422 is written before any handler runs and it is always JSON. Without `invalid()`, a browser gets JSON.

The fix is one method on the handler. When the request prefers HTML and a handler of the route implements `Semitexa\Core\Contract\HandlesInvalidPayloadInterface`, the pipeline carries on as for a valid request (every phase, authorization included) and calls `invalid()` where it would have called `handle()`:

```php
public function invalid(object $payload, array $errors, ResourceInterface $resource): ResourceInterface;
```

- `$payload` is the hydrated payload: it still holds what was submitted.
- `$errors` is the `array<string, list<string>>` map, field name to messages, exactly as `validate()` returned it.
- `$resource` is the same resource `handle()` would get, with its status already set to 422.

### The payload

Setters only store (and trim); every rule is in `validate()`, so all errors come back at once and every submitted value is still on the payload. Give each property a default: a field missing from the post never reaches its setter.

```php
namespace App\Contact\Application\Payload\Request;

use App\Contact\Application\Resource\Response\ContactPageResource;
use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Contract\ValidatablePayloadInterface;
use Semitexa\Core\Validation\Trait\FormatValidationTrait;
use Semitexa\Core\Validation\Trait\PresenceValidationTrait;
use Semitexa\Core\Validation\Trait\StringValidationTrait;

#[AsPublicPayload(path: '/contact', methods: ['POST'], responseWith: ContactPageResource::class)]
final class ContactSendPayload implements ValidatablePayloadInterface
{
    use PresenceValidationTrait;
    use StringValidationTrait;
    use FormatValidationTrait;

    protected string $name = '';
    protected string $email = '';
    protected string $message = '';

    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = trim($name); }

    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): void { $this->email = trim($email); }

    public function getMessage(): string { return $this->message; }
    public function setMessage(string $message): void { $this->message = trim($message); }

    public function validate(): array
    {
        $errors = [];

        $this->validateNotBlank($errors, 'name', $this->name);
        $this->validateLength($errors, 'name', $this->name, min: 2, max: 100);

        $this->validateNotBlank($errors, 'email', $this->email);
        $this->validateRfcEmail($errors, 'email', $this->email);

        $this->validateNotBlank($errors, 'message', $this->message);
        $this->validateMaxLength($errors, 'message', $this->message, 2000);

        return $errors;
    }
}
```

### The resource

`HtmlResponse::with()` is protected, so the page resource publishes typed `with*()` methods, as every SSR resource does:

```php
namespace App\Contact\Application\Resource\Response;

use Semitexa\Core\Attribute\AsResource;
use Semitexa\Ssr\Application\Service\Http\Response\HtmlResponse;

#[AsResource(handle: 'contact', template: '@project-layouts-Contact/pages/contact.html.twig')]
class ContactPageResource extends HtmlResponse
{
    /** @param array<string, string> $values */
    public function withValues(array $values): static
    {
        return $this->with('values', $values);
    }

    /** @param array<string, list<string>> $errors */
    public function withErrors(array $errors): static
    {
        return $this->with('errors', $errors);
    }
}
```

### The handler

```php
namespace App\Contact\Application\Handler\PayloadHandler;

use App\Contact\Application\Payload\Request\ContactSendPayload;
use App\Contact\Application\Resource\Response\ContactPageResource;
use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Contract\HandlesInvalidPayloadInterface;
use Semitexa\Core\Contract\ResourceInterface;
use Semitexa\Core\Contract\TypedHandlerInterface;

#[AsPayloadHandler(payload: ContactSendPayload::class, resource: ContactPageResource::class)]
final class ContactSendHandler implements TypedHandlerInterface, HandlesInvalidPayloadInterface
{
    public function handle(ContactSendPayload $payload, ContactPageResource $resource): ContactPageResource
    {
        // Reached only with a valid payload: save, send, then leave the POST.
        $resource->setRedirect('/contact/sent');

        return $resource;
    }

    public function invalid(object $payload, array $errors, ResourceInterface $resource): ResourceInterface
    {
        if (!$payload instanceof ContactSendPayload || !$resource instanceof ContactPageResource) {
            return $resource;
        }

        return $resource
            ->pageTitle('Contact')
            ->withValues([
                'name' => $payload->getName(),
                'email' => $payload->getEmail(),
                'message' => $payload->getMessage(),
            ])
            ->withErrors($errors);
    }
}
```

The signature of `invalid()` is the interface's and cannot be narrowed: PHP refuses `invalid(ContactSendPayload $payload, ...)` as an incompatible declaration. Narrow inside with `instanceof`, as above.

### The template

The page that showed the empty form shows it again; `values` and `errors` are simply empty on the first GET.

```twig
<form method="post" action="/contact">
  <label for="name">Name</label>
  <input id="name" name="name" value="{{ values.name|default('') }}"
         {% if errors.name is defined %}aria-invalid="true"{% endif %}>
  {% for message in errors.name|default([]) %}
    <p role="alert">{{ message }}</p>
  {% endfor %}
  {# email and message the same way #}
  <button type="submit">Send</button>
</form>
```

### Who gets what

| Request | Handler implements `invalid()` | Answer |
|---|---|---|
| Browser form post (`Accept` lists `text/html`) | yes | the page from `invalid()`, status 422 |
| Browser form post | no | JSON `{"errors": {...}}`, 422, shown as text |
| `Accept: application/json` | either | JSON `{"errors": {...}}`, 422 |
| `Accept: */*`, or no `Accept` (a bare `fetch()`, `curl`) | either | JSON `{"errors": {...}}`, 422 |

The JSON body is the flat envelope from [Validation flow](#validation-flow); it is the same for a failed `validate()`, a `ValidationException` thrown by a setter and a value of the wrong type.

### What `invalid()` does and does not do

- **It runs instead of `handle()`.** Nothing `handle()` loads is there: `invalid()` must give the resource everything the template needs (select options, the page title).
- **Only handlers that implement the interface run.** Other handlers of the route are skipped; the request was not valid.
- **Authorization and the other pipeline phases still run**, as for a valid request.
- **The status is 422.** Returning a resource at 200 is turned back into 422; any other status `invalid()` sets is kept (a redirect through `setRedirect()`, for one).
- **The resource must be a `ResourceResponse`** (`HtmlResponse` is one). On anything else the JSON 422 stays.
- **Errors from setters and type mismatches arrive here too**, under the same field keys, but a setter that throws stops hydration: one error is reported, and the fields after it are never set, so their old values are lost. That is why a form keeps its rules in `validate()`.
- **A body that cannot be read at all** is reported under the key `_body`. Show errors whose key is not one of your fields somewhere on the page.
- **Messages are English literals.** The bundled validators take no message argument (`'This value should not be blank.'`). For another language, map the messages in `invalid()` or in the template, or write the rule as a [custom validator](#custom-validators) with your own text.

Where validation belongs for each kind of endpoint: [Payload Validation](payload-validation.md).

## A protected payload — profile update

```php
use Semitexa\Authorization\Attribute\AsProtectedPayload;
use Semitexa\Core\Validation\Trait\DateTimeValidationTrait;
use Semitexa\Core\Validation\Trait\DomainValidationTrait;

#[AsProtectedPayload(path: '/validation-demo/profile', methods: ['POST'], ...)]
final class ProfileUpdatePayload implements ValidatablePayloadInterface
{
    use PresenceValidationTrait;
    use StringValidationTrait;
    use DateTimeValidationTrait;
    use DomainValidationTrait;

    private string $displayName = '';
    private string $locale = '';
    private string $timezone = '';
    private ?string $birthDate = null;
    private bool $marketingOptIn = false;

    // ... setters elided ...

    public function validate(): array
    {
        $errors = [];

        $this->validateNotBlank($errors, 'displayName', $this->displayName);
        $this->validateLength($errors, 'displayName', $this->displayName, min: 2, max: 80);

        $this->validateNotBlank($errors, 'locale', $this->locale);
        $this->validateLocaleCode($errors, 'locale', $this->locale);

        $this->validateNotBlank($errors, 'timezone', $this->timezone);
        $this->validateTimezone($errors, 'timezone', $this->timezone);

        if ($this->validateOptional($errors, 'birthDate', $this->birthDate)) {
            $this->validateDate($errors, 'birthDate', $this->birthDate);
            if (($errors['birthDate'] ?? []) === []) {
                $this->validatePast($errors, 'birthDate', $this->birthDate);
            }
        }

        return $errors;
    }
}
```

`#[AsProtectedPayload]` makes the framework's `PreHydrationAuthGate` reject anonymous requests with 401 before any of this code runs. The validate() method is reached only for authenticated requests; an unauthenticated request never sees a validation error message.

## A service payload — conditional + nested

```php
use Semitexa\Authorization\Attribute\AsServicePayload;
use Semitexa\Core\Validation\Trait\ConditionalValidationTrait;
use Semitexa\Core\Validation\Trait\NumericValidationTrait;

#[AsServicePayload(path: '/validation-demo/product-sync', methods: ['POST'], ...)]
final class ProductSyncPayload implements ValidatablePayloadInterface
{
    use PresenceValidationTrait;
    use StringValidationTrait;
    use FormatValidationTrait;
    use NumericValidationTrait;
    use ChoiceValidationTrait;
    use CollectionValidationTrait;
    use ConditionalValidationTrait;
    use DomainValidationTrait;

    // ... fields + setters elided ...

    public function validate(): array
    {
        $errors = [];

        $this->validateNotBlank($errors, 'sku', $this->sku);
        $this->validateRegex($errors, 'sku', $this->sku, '/^[A-Z0-9-]{2,40}$/');
        $this->validatePositiveOrZero($errors, 'price', $this->price);
        $this->validateCurrencyCode($errors, 'currency', $this->currency);
        $this->validateChoice($errors, 'type', $this->type, ['physical', 'digital']);

        $isPhysical = $this->type === 'physical';
        $isDigital  = $this->type === 'digital';
        $this->validateRequiredIf($errors, 'weight', $this->weight, $isPhysical);
        $this->validateRequiredIf($errors, 'downloadUrl', $this->downloadUrl, $isDigital);

        $this->validateArrayOf($errors, 'variants', $this->variants, self::variantValidator());

        return $errors;
    }

    private static function variantValidator(): callable
    {
        return static function (array &$errors, string $field, mixed $variant): void {
            if (! is_array($variant)) {
                return;
            }
            // ...errors land at variants[0].sku, variants[0].price, ...
        };
    }
}
```

`#[AsServicePayload]` requires service-domain auth (machine token, signed webhook, mTLS); a user token is rejected at the access boundary, not by validation. Inside `validate()`, `validateRequiredIf` fires only when `$isPhysical`/`$isDigital` is true, so the conditional wiring is a single line per branch.

## Composite — accept email or URL or phone

```php
use Semitexa\Core\Validation\Trait\CompositeValidationTrait;

final class ContactMethodPayload implements ValidatablePayloadInterface
{
    use PresenceValidationTrait;
    use FormatValidationTrait;
    use DomainValidationTrait;
    use CompositeValidationTrait;

    private string $contactMethod = '';
    public function setContactMethod(string $value): void { $this->contactMethod = $value; }

    public function validate(): array
    {
        $errors = [];
        $this->validateNotBlank($errors, 'contactMethod', $this->contactMethod);
        if (($errors['contactMethod'] ?? []) !== []) {
            return $errors;
        }

        $this->validateAnyOf($errors, 'contactMethod', $this->contactMethod, [
            fn (array &$e, string $f, mixed $v) => $this->validateRfcEmail($e, $f, is_string($v) ? $v : null),
            fn (array &$e, string $f, mixed $v) => $this->validateUrl($e, $f, is_string($v) ? $v : null),
            fn (array &$e, string $f, mixed $v) => $this->validateE164Phone($e, $f, is_string($v) ? $v : null),
        ]);

        return $errors;
    }
}
```

`validateAnyOf` runs each branch against an isolated scratch buffer and emits **one** stable field-level message on failure. Branch-specific phrasing ("valid email", "valid URL", "valid E.164") never leaks into the public envelope.

## Category overview

| Category | Trait | Headline methods |
|---|---|---|
| Presence | `PresenceValidationTrait` | `validateRequired`, `validateNotBlank`, `validateBlank`, `validateNotNull`, `validateIsNull`, `validateOptional` |
| Type | `TypeValidationTrait` | `validateString`, `validateInteger`, `validateFloat`, `validateNumber`, `validateBoolean`, `validateArray`, `validateObject`, `validateIterable`, `validateEnumCase`, `validateBackedEnumValue` |
| String | `StringValidationTrait` | `validateLength`, `validateMinLength`, `validateMaxLength`, `validateExactLength`, `validateRegex`, `validateAlpha`, `validateAlphaNumeric`, `validateStartsWith`/`EndsWith`/`Contains`/`NotContains`, `validateLowercase`/`Uppercase` |
| Format | `FormatValidationTrait` | `validateEmail` (lenient), `validateRfcEmail` (`filter_var`), `validateUrl`, `validateUuid`, `validateUlid`, `validateIp`, `validateHostname`, `validateJsonString`, `validateSlug` |
| Numeric | `NumericValidationTrait` | `validatePositive`, `validatePositiveOrZero`, `validateNegative`, `validateNegativeOrZero`, `validateGreaterThan[OrEqual]`, `validateLessThan[OrEqual]`, `validateRange`, `validateDivisibleBy`, `validateMultipleOf` |
| DateTime | `DateTimeValidationTrait` | `validateDate`, `validateDateTime`, `validateTime`, `validateBefore[OrEqual]`, `validateAfter[OrEqual]`, `validatePast[OrPresent]`, `validateFuture[OrPresent]` |
| Choice | `ChoiceValidationTrait` | `validateChoice`, `validateNotIn`, `validateCount`, `validateMinCount`, `validateMaxCount`, `validateExactCount`, `validateUnique`, `validateEnumChoice`, `validateBackedEnumChoice` |
| Collection | `CollectionValidationTrait` | `validateArrayOf`, `validateListOf`, `validateMapOf`, `validateCollection`, `validateRequiredKeys`, `validateOptionalKeys`, `validateNoExtraKeys`, `validateAtLeastOneKey`, `validateExactlyOneKey`, `validateMutuallyExclusiveKeys` |
| Comparison | `ComparisonValidationTrait` | `validateEqualTo`, `validateNotEqualTo`, `validateIdenticalTo`, `validateNotIdenticalTo`, `validateSameAsField`, `validateDifferentFromField` |
| Conditional | `ConditionalValidationTrait` | `validateRequiredIf`, `validateProhibitedIf`, `validateRequiredWith`, `validateRequiredWithout`, `validateIf`, `validateSometimes` |
| Composite | `CompositeValidationTrait` | `validateAll`, `validateAnyOf`, `validateOneOf`, `validateNoneOf`, `validateSequentially` |
| Domain | `DomainValidationTrait` | `validateCountryCode`, `validateCurrencyCode`, `validateLocaleCode`, `validateTimezone`, `validateE164Phone`, `validateHexColor`, `validateBase64`, `validateMimeType` |

The unit tests under `packages/semitexa-core/tests/Unit/Validation/` are the exact behaviour contract — refer to them for the precise edge cases each method handles.

## Comparison messages never leak values

Cross-field comparison messages mention only the other field name:

```text
This value should match password.
This value should differ from old_password.
```

Raw rejected or expected values are never embedded — passwords, secret tokens, or any other sensitive data never appear in the envelope through these validators.

## Custom validators

A custom rule is a trait method that follows the accumulator convention:

```php
namespace App\Validation\Trait;

trait OurDomainValidationTrait
{
    /** @param array<string, list<string>> $errors */
    protected function validateOurInternalCode(array &$errors, string $field, ?string $value): void
    {
        if ($value === null) {
            return;
        }
        if (preg_match('/^[A-Z]{3}-\d{4}$/', $value) !== 1) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value should be a valid internal code.';
        }
    }
}
```

`use OurDomainValidationTrait;` from any payload, call from `validate()`. No registry, no metadata. A one-off rule can also be passed directly into `validateAnyOf`, `validateAll`, or any `Collection` callback as a callable closure.

## Deferred validators

These constraints are intentionally **not** in the bundled traits. Implementing them weakly under strong names would mislead callers; they remain available as future dependency-aware extensions:

| Validator | Reason for deferral |
|---|---|
| IBAN, BIC, VAT | Need country-specific check digits and tables |
| ISBN, ISSN, Luhn | Need check-digit algorithms |
| Region-aware phone | Needs `giggsey/libphonenumber-for-php` (an external dep) |
| File / Image | Tied to upload pipeline; out of scope of payload validation |
| PasswordStrength | Policy-dependent; better as an opt-in module |
| Per-error machine codes | Touches three error transports (HTTP, External API, GraphQL); deferred |

E.164 phone validation is implemented as a **format-only** check via `validateE164Phone` and is documented as such — it does not validate carrier or region.

## Testing validation

Validation tests fall in two layers.

**Unit-level** — instantiate the trait via an anonymous host class, call the validator directly, assert the accumulated error map. The pattern used across `packages/semitexa-core/tests/Unit/Validation/` is:

```php
$errors = [];
$host = new class () {
    use StringValidationTrait;
    public function length(array &$errors, string $f, ?string $v, int $min, int $max): void
    { $this->validateLength($errors, $f, $v, $min, $max); }
};
$host->length($errors, 'name', 'ab', 3, 10);
self::assertSame(['name' => ['This value should be at least 3 characters.']], $errors);
```

**Runtime-level** — drive the actual `Application::handleRequest` pipeline with a synthetic POST request and assert the 422 envelope. `src/modules/ValidationDemo/tests/RuntimeValidationPipelineTest.php` is the canonical example: each route gets one happy-path test, one test per validation failure shape, and lifecycle tests proving validation state does not leak across requests.

Run the validation tests with the framework's test runner:

```bash
bin/semitexa test:run packages/semitexa-core/tests/Unit/Validation
bin/semitexa test:run src/modules/ValidationDemo/tests
```

The host-side PHPUnit binary is not the supported entry point.
