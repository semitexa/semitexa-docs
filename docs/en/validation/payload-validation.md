---
id: validation/payload-validation
section: validation
slug: payload-validation
title: Payload Validation
summary: Where validation sits in the pipeline, how payload rules and helpers are written, hydration, the failure response, and session and cookie payloads.
order: 30
locale: en
status: canonical
verified_against: 2026.09.19.1020
keywords:
  - payload rules
  - hydration
  - validation pipeline
  - HandlesInvalidPayloadInterface
  - HTML form validation
---

# Payload Validation

## Idea

Payload DTOs are the boundary between external data and application code. Semitexa already hydrates payloads through typed setters, so field ownership and field-level validation should live in those setters instead of in one DTO-wide `validate()` method.

Invalid input is still rejected as `422 Unprocessable Entity`, but the rejection should be expressed by the field that failed, not by a monolithic post-hydration pass.

There is one case where the post-hydration pass is the right tool: **an HTML form a browser posts**. It needs every error at once and every value the visitor typed, and a setter that throws gives neither. That case uses `validate()` plus the handler's `invalid()`; see [HTML forms](#html-forms).

## Pipeline

1. **Hydrate** - `PayloadHydrator::hydrate($dto, $request)` fills the DTO using the setter convention: for each key in raw data (JSON/POST/query + path params), the hydrator calls `set{CamelCase}($value)` if the method exists.
2. **Normalize and guard** - each setter can normalize its input and reject invalid values immediately with a field-aware exception such as `Semitexa\Core\Exception\ValidationException`.
3. **Validate across fields** - if the payload implements `Semitexa\Core\Contract\ValidatablePayloadInterface`, its `validate()` runs once, after every setter.
4. **Return 422 on failure** - the request pipeline answers 422 before `handle()` runs: as JSON, or, for an HTML request whose handler implements `HandlesInvalidPayloadInterface`, through that handler's `invalid()`.
5. **Handle** - the handler receives a DTO whose individual fields have already been normalized and guarded by their own setters.

## Payload rules

- **Fields:** `private` or `protected` only.
- **Access:** only through **getters** (`get*`) and **setters** (`set*`).
- **Validation ownership:** field-level rules belong in the setter for that field.
- **Cross-field rules:** keep them explicit and local. If a rule spans multiple fields, prefer a dedicated helper method or a small field-specific exception path over a hidden DTO-wide validation bag.

## Validation helpers

Core still provides reusable building blocks for teams that want shared validation logic:

- `Semitexa\Core\Exception\ValidationException` for structured field errors
- the existing validation traits in `Semitexa\Core\Validation\Trait\*` for shared rule helpers

These helpers are still useful, but they no longer imply that every payload must funnel all rules through `validate()`.

## Example

```php
use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Exception\ValidationException;

#[AsPublicPayload(path: '/contact', methods: ['POST'], responseWith: ContactFormResource::class)]
class ContactFormPayload
{
    protected string $email = '';
    protected string $message = '';

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): void
    {
        $email = trim($email);

        if ($email === '') {
            throw new ValidationException(['email' => ['Email is required.']]);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException(['email' => ['Email must be valid.']]);
        }

        $this->email = $email;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function setMessage(string $message): void
    {
        $message = trim($message);

        if ($message === '') {
            throw new ValidationException(['message' => ['Message is required.']]);
        }

        if (mb_strlen($message) > 5000) {
            throw new ValidationException(['message' => ['Message must stay below 5000 characters.']]);
        }

        $this->message = $message;
    }
}
```

## Hydration

- Data keys such as `email` and `flash_message` are converted to setter names such as `setEmail` and `setFlashMessage`.
- Path params from the route, such as `{id}`, are passed as key `id` and trigger `setId($value)`.
- The hydrator uses the setter's parameter type to cast the value before calling it.

## Response on validation failure

- **Status:** 422 Unprocessable Entity.
- **Body:** `{ "errors": { "fieldName": ["message1", "message2"], ... } }`. It is the same flat body for a `ValidationException` thrown by a setter, a value of the wrong type, and a non-empty map from `validate()`.
- **Always JSON**, whatever the client asked for, unless the handler implements `invalid()` (next section).

A `ValidationException` thrown later, from inside `handle()`, is a domain rule and not a malformed request. It gets the domain error envelope, `{ "error": ..., "message": ..., "context": { "errors": {...} } }`, also with 422.

## HTML forms

Without `invalid()`, a browser gets JSON: a visitor who posts a form with a mistake in it sees `{"errors": {...}}` as text. Do not answer that by moving the checks into `handle()`. Keep them on the payload and let the handler show the form again:

- the payload implements `ValidatablePayloadInterface` and checks every field in `validate()` with the accumulator traits (`Semitexa\Core\Validation\Trait\*`), so all errors are reported together and all submitted values stay on the payload;
- the handler implements `Semitexa\Core\Contract\HandlesInvalidPayloadInterface`:

```php
public function invalid(object $payload, array $errors, ResourceInterface $resource): ResourceInterface
```

For a request that prefers HTML (`Accept` lists `text/html`; a wildcard or no `Accept` does not count), `invalid()` runs in place of `handle()` with the hydrated payload, the `field => [messages]` map and the route's resource, already at status 422. It puts the old values and the errors on the resource and returns it; the page template renders as usual. A JSON request to the same route still gets the flat JSON 422.

The full pattern, with payload, resource, handler and template, and the limits of `invalid()`: [Core Validators: an HTML form](core-validators.md#an-html-form-show-it-again-with-its-errors).

## Which one to use

| Endpoint | Where the rules go | Why |
|---|---|---|
| JSON API, webhook, machine client | the setter, throwing `ValidationException` | fails fast; the client gets the field and fixes it |
| Rule across several fields | `validate()` | it runs after every setter |
| Required field | `validate()` | a key the request does not carry never calls its setter |
| HTML form posted by a browser | `validate()` plus the handler's `invalid()` | all errors at once, old values kept, an HTML answer |
| Rule about stored state ("this email is already registered") | `handle()`, throwing a domain exception | it is a domain rule, not a malformed request |

## Session / Cookie payloads

The same rules apply: protected fields, getters/setters, and explicit field guards. Session and queue payloads are serialized with `PayloadSerializer`, which uses getters for `toArray()` and setters for `hydrate()`.

## Authoring note

Setter-owned validation is the default for a payload a program calls. `validate()` is for what a setter cannot see (another field, a missing key) and for HTML forms, where it is paired with `invalid()`. In every case the rules stay in the payload: `handle()` is never where input is checked.
