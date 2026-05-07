---
id: validation/core-validators
section: validation
slug: core-validators
title: Core Validators
summary: Trait-based validation primitives covering presence, type, string, format, numeric, datetime, choice, collection, comparison, conditional, composite, and domain rules — composed into payload `validate()` methods that the framework runs automatically before handlers.
order: 10
locale: en
status: published
keywords:
  - ValidatablePayloadInterface
  - PresenceValidationTrait
  - StringValidationTrait
  - FormatValidationTrait
  - CollectionValidationTrait
  - ConditionalValidationTrait
  - CompositeValidationTrait
  - validation envelope
  - nested validation paths
  - "Path::join"
---

# Core Validators

Semitexa ships a dependency-free, trait-based validation surface. Drop a category trait into a Payload DTO, return an error map from `validate()`, and the framework converts it into a 422 response automatically.

## Philosophy

- **Validation lives on the payload.** Handlers receive validated payloads; they do not re-validate, and they do not return 422 themselves.
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

1. The HTTP request reaches `RouteExecutor::fillAndValidatePayload()`.
2. `PayloadHydrator::hydrate()` invokes setters; setter-time `ValidationException` becomes 422 before `validate()` runs.
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

5. The payload reaches the handler only when `validate()` returned `[]`.

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
