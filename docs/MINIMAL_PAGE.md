# A Minimal Working Page — Semitexa

> Entry points: [AI quick brief](ai/MINIMAL_PAGE.md) · [Human entry](hm/MINIMAL_PAGE.md)  
> Also: [Get Started](GET_STARTED.md) · [About Semitexa](../README.md)

This is the **canonical guide** for creating one minimal HTML page in Semitexa: one route, one Payload, one Handler, one Resource, and one Twig template.

The core idea is unchanged: **the Payload is the shield**. Input is accepted, normalized, and validated there. The handler receives only a trusted Payload, and the Resource carries the output shape all the way to Twig.

This page should be the moment where Semitexa stops sounding like a philosophy and starts feeling obvious.

---

## What We Are Building

Route:

- `GET /minimal?name=World`

Files:

- module `composer.json`
- request Payload
- page Resource
- Twig template
- handler

Flow:

- `Payload -> Handler -> Resource -> Twig`

That flow is the point. One clear request contract. One clear handler. One clear response path.

---

## Prerequisites

- A Semitexa project already installed and runnable.
- Read [GET_STARTED.md](GET_STARTED.md) if the app is not running yet.
- New routes live only in modules: `src/modules/`, `packages/`, or installed packages.

---

## Step 1: Create the module

Example: `src/modules/Website/`

Add `composer.json`:

```json
{
  "name": "semitexa/module-website",
  "type": "semitexa-module",
  "require": {
    "php": "^8.4",
    "semitexa/core": "*",
    "semitexa/ssr": "*"
  },
  "autoload": {
    "psr-4": {
      "Semitexa\\Modules\\Website\\": ""
    }
  }
}
```

Then run:

```bash
composer dump-autoload
```

---

## Step 2: Create the Payload

Path:

- `Application/Payload/Request/MinimalPagePayload.php`

Example:

```php
<?php

declare(strict_types=1);

namespace Semitexa\Modules\Website\Application\Payload\Request;

use Semitexa\Core\Attributes\AsPayload;
use Semitexa\Core\Contract\ValidatablePayload;
use Semitexa\Core\Http\PayloadValidationResult;
use Semitexa\Core\Validation\Trait\LengthValidationTrait;
use Semitexa\Modules\Website\Application\Resource\Response\MinimalPageResource;

#[AsPayload(path: '/minimal', methods: ['GET'], responseWith: MinimalPageResource::class)]
final class MinimalPagePayload implements ValidatablePayload
{
    use LengthValidationTrait;

    protected string $name = '';

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = trim($name);
    }

    public function validate(): PayloadValidationResult
    {
        $errors = [];
        $this->validateLength('name', $this->name, 1, 100, $errors);
        return new PayloadValidationResult(empty($errors), $errors);
    }
}
```

What matters:

- Payload defines path, methods, and response type.
- Framework hydrates via setters.
- Invalid input returns `422` before the handler runs.
- There is no `PayloadInterface` here. Plain class plus `ValidatablePayload` is the modern path.

This is one of the strongest Semitexa ideas: the handler should receive data that is already shaped and safe to trust.

---

## Step 3: Create the Resource

Path:

- `Application/Resource/Response/MinimalPageResource.php`

Example:

```php
<?php

declare(strict_types=1);

namespace Semitexa\Modules\Website\Application\Resource\Response;

use Semitexa\Core\Attributes\AsResource;
use Semitexa\Core\Contract\ResourceInterface;
use Semitexa\Ssr\Http\Response\HtmlResponse;

#[AsResource(
    handle: 'minimal_page',
    template: '@project-layouts-Website/pages/minimal.html.twig',
)]
final class MinimalPageResource extends HtmlResponse implements ResourceInterface
{
    public function withPageTitle(string $pageTitle): self
    {
        return $this->with('pageTitle', $pageTitle);
    }

    public function withHeading(string $heading): self
    {
        return $this->with('heading', $heading);
    }

    public function withMessage(string $message): self
    {
        return $this->with('message', $message);
    }

    public function withFooter(string $footer): self
    {
        return $this->with('footer', $footer);
    }
}
```

This is the canonical SSR pattern:

- `HtmlResponse` for HTML pages
- `#[AsResource]` for handle + template declaration
- typed `with*()` methods instead of raw render-context arrays

---

## Step 4: Create the Twig template

Path:

- `Application/View/templates/pages/minimal.html.twig`

Example:

```twig
{% extends "@project-layouts-Website/layouts/base.html.twig" %}
{% block title %}{{ pageTitle|default('Minimal page') }}{% endblock %}

{% block main %}
  <section class="minimal-page">
    <h1>{{ heading|default('Minimal page') }}</h1>
    <p>{{ message|default('') }}</p>
  </section>
{% endblock %}

{% block footer %}{{ footer|default('') }}{% endblock %}
```

The template reads typed render variables directly: `pageTitle`, `heading`, `message`, `footer`.

---

## Step 5: Create the Handler

Path:

- `Application/Handler/PayloadHandler/MinimalPageHandler.php`

Example:

```php
<?php

declare(strict_types=1);

namespace Semitexa\Modules\Website\Application\Handler\PayloadHandler;

use Semitexa\Core\Attributes\AsPayloadHandler;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Modules\Website\Application\Payload\Request\MinimalPagePayload;
use Semitexa\Modules\Website\Application\Resource\Response\MinimalPageResource;

#[AsPayloadHandler(payload: MinimalPagePayload::class, resource: MinimalPageResource::class)]
final class MinimalPageHandler implements TypedHandlerInterface
{
    public function handle(MinimalPagePayload $payload, MinimalPageResource $resource): MinimalPageResource
    {
        $name = $payload->getName();

        return $resource
            ->pageTitle('Minimal page')
            ->withPageTitle('Minimal page')
            ->withHeading('Hello, ' . $name)
            ->withMessage('This page was rendered through Payload -> Handler -> Resource -> Twig.')
            ->withFooter('Semitexa keeps the request path explicit on purpose.');
    }
}
```

The handler trusts the validated Payload. It does not parse raw request data again.

It also does not:

- implement deprecated `HandlerInterface`
- build raw response arrays
- call `setRenderHandle()` manually
- call `renderTemplate()` manually when the template is already declared on the Resource

That is where the relief should be felt. You are no longer writing "maybe" code. The contract is already decided upstream.

---

## Step 6: Reload and verify

After adding the new classes, reload or restart the app if needed:

```bash
bin/semitexa server:stop
bin/semitexa server:start
```

Then open:

- `GET /minimal?name=World`

Expected result:

- HTML page rendered through Twig
- `422` if `name` is missing or invalid

Do not treat `bin/semitexa registry:sync` as a required manual step for ordinary payload changes.

If this page feels straightforward, that is the intended effect. The framework should reduce branching in your head, not add more.

---

## If Something Goes Wrong

- **404**: verify the class is inside a discovered module and the namespace matches module PSR-4.
- **Class not found**: run `composer dump-autoload`.
- **Need broader route reference**: read `vendor/semitexa/core/docs/ADDING_ROUTES.md`.

If the route still feels harder than it should, the problem is usually one of three things: module discovery, namespace mismatch, or runtime reload.

---

## Mapping

| Goal | Document or command |
|------|----------------------|
| Install and run app | [GET_STARTED.md](GET_STARTED.md) |
| Add routes / module layout | `vendor/semitexa/core/docs/ADDING_ROUTES.md` |
| Payload validation | `vendor/semitexa/core/docs/PAYLOAD_VALIDATION.md` |
| Practical implementation rules | [AI_BEST_PRACTICES.md](AI_BEST_PRACTICES.md) |

---

## AI Quick Brief

1. Create module and `composer.json`.
2. Create `Application/Payload/Request/*Payload.php`.
3. Create `Application/Resource/Response/*Resource.php` extending `HtmlResponse`.
4. Declare `#[AsResource(handle: '...', template: '...')]` on the page resource.
5. Create `Application/View/templates/pages/*.html.twig`.
6. Create `Application/Handler/PayloadHandler/*Handler.php` implementing `TypedHandlerInterface`.
7. Restart app and verify route.
