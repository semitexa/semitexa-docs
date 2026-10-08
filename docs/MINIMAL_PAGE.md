# A Minimal Working Page — Semitexa

> Also: [Get Started](GET_STARTED.md) · [AI Reference](../AI_REFERENCE.md)

This is the **canonical guide** for creating one minimal HTML page in Semitexa: one route, one Payload, one Handler, one Resource, and one Twig template.

The core idea: **the Payload is the shield**. Input is accepted, normalized, and validated there. The handler receives only a trusted Payload, and the Resource carries the output shape all the way to Twig.

The starter `Hello` module in a new project (`src/modules/Hello/`) is a working example of everything on this page.

---

## What We Are Building

Route: `GET /minimal?name=World`

Flow: `Payload -> Handler -> Resource -> Twig`

Files, all inside `src/modules/Website/src/Application/`:

| File | Role |
|------|------|
| `Payload/Request/MinimalPayload.php` | route, methods, input validation |
| `Handler/PayloadHandler/MinimalHandler.php` | the use case |
| `Resource/Response/MinimalResponse.php` | the data handed to the template |
| `View/templates/pages/minimal.html.twig` | presentation |

---

## Prerequisites

- A running project — see [GET_STARTED.md](GET_STARTED.md).
- Run the commands below from the project root. `bin/semitexa` runs them inside the app container.

---

## Step 1: Create the module

```bash
bin/semitexa make:module --name=Website --target=custom --write
```

This creates `src/modules/Website/` with `src/` (runtime code, under `src/Application/` and `src/Domain/`) and `tests/`. Generators are dry-run by default; `--write` creates the files.

How the module is loaded:

- Every directory under `src/modules/` is a module. At boot, `LocalModuleAutoloadRegistrar` maps `App\Modules\Website\` to `src/modules/Website/src/`. There is no `composer dump-autoload` step and no registry entry to add.
- The module's templates are addressed as `@project-layouts-Website/...` (the alias is the directory name).
- A `composer.json` in the module is optional. If you add one (the `Hello` module has one), keep the mapping the registrar uses:

  ```json
  {
      "name": "app/module-website",
      "type": "semitexa-module",
      "autoload": {
          "psr-4": {
              "App\\Modules\\Website\\": "src/"
          }
      }
  }
  ```

---

## Step 2: Generate the page

```bash
bin/semitexa make:page --module=Website --name=Minimal --path=/minimal --method=GET --access=public --write
```

Options worth knowing:

- `--access` is `protected` by default (signed-in users only). Use `public` for a page anyone can open, or `service` for machine callers.
- `--with-assets` also creates CSS/JS stubs and `assets.json`.
- `--no-test` skips the two test scaffolds that are otherwise created in `src/modules/Website/tests/`.
- Leave out `--write` to print the planned files without creating them.

It creates the four files from the table above plus two tests. The generated classes use the `Semitexa\Modules\Website\` namespace; the registrar maps that prefix to the same directory, so it works, but `App\Modules\Website\` is the canonical name (it is what the `Hello` module uses), and the listings below use it.

**Fix the generated template first.** It extends `@layouts/base.html.twig`, which no package ships, so the page answers 500 until you change it. Step 4 replaces it with a template that extends a layout the bundled theme provides.

---

## Step 3: The Payload

`src/modules/Website/src/Application/Payload/Request/MinimalPayload.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Website\Application\Payload\Request;

use App\Modules\Website\Application\Resource\Response\MinimalResponse;
use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Exception\ValidationException;

#[AsPublicPayload(path: '/minimal', methods: ['GET'], responseWith: MinimalResponse::class)]
final class MinimalPayload
{
    protected string $name = 'World';

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $trimmed = trim($name);
        $length  = strlen($trimmed);
        if ($length < 1 || $length > 100) {
            throw new ValidationException(['name' => ['Must be between 1 and 100 characters.']]);
        }
        $this->name = $trimmed;
    }
}
```

What matters:

- The attribute declares path, methods and the response type. `#[AsPublicPayload]` makes the route anonymous; `#[AsProtectedPayload]` and `#[AsServicePayload]` (from `Semitexa\Authorization\Attribute\`) require a signed-in user or a machine credential.
- The framework hydrates the payload through its setters. `?name=Ada` calls `setName('Ada')`.
- A setter throws `Semitexa\Core\Exception\ValidationException` to reject input. The framework answers `422` with `{"errors":{"name":[...]}}` before the handler runs.
- The payload is a plain class: no base class and no marker interface.

---

## Step 4: The Resource and the template

`src/modules/Website/src/Application/Resource/Response/MinimalResponse.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Website\Application\Resource\Response;

use Semitexa\Core\Attribute\AsResource;
use Semitexa\Core\Contract\ResourceInterface;
use Semitexa\Ssr\Application\Service\Http\Response\HtmlResponse;

#[AsResource(handle: 'minimal', template: '@project-layouts-Website/pages/minimal.html.twig')]
final class MinimalResponse extends HtmlResponse implements ResourceInterface
{
    public function withHeading(string $heading): self
    {
        return $this->with('heading', $heading);
    }

    public function withMessage(string $message): self
    {
        return $this->with('message', $message);
    }
}
```

- `HtmlResponse` is the base for HTML pages.
- `#[AsResource]` declares the render handle and the template.
- Typed `with*()` methods set template variables; `with('heading', ...)` makes `{{ heading }}` available.

`src/modules/Website/src/Application/View/templates/pages/minimal.html.twig`:

```twig
{% extends '@project-layouts-theme-base/layouts/one-column.html.twig' %}

{% block title %}{{ heading }}{% endblock %}

{% block main %}
  <section class="minimal-page">
    <h1>{{ heading }}</h1>
    <p>{{ message }}</p>
  </section>
{% endblock %}
```

`one-column.html.twig` comes with `semitexa/theme` (part of `semitexa/ultimate`) and defines the `title`, `main` and `footer` blocks; `two-columns-left`, `two-columns-right`, `three-columns` and `marketing` sit next to it. A page can also be a complete standalone HTML document, as `Hello`'s `hello.html.twig` is.

---

## Step 5: The Handler

`src/modules/Website/src/Application/Handler/PayloadHandler/MinimalHandler.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Website\Application\Handler\PayloadHandler;

use App\Modules\Website\Application\Payload\Request\MinimalPayload;
use App\Modules\Website\Application\Resource\Response\MinimalResponse;
use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Contract\TypedHandlerInterface;

#[AsPayloadHandler(payload: MinimalPayload::class, resource: MinimalResponse::class)]
final class MinimalHandler implements TypedHandlerInterface
{
    public function handle(MinimalPayload $payload, MinimalResponse $resource): MinimalResponse
    {
        return $resource
            ->withHeading('Hello, ' . $payload->getName())
            ->withMessage('Rendered through Payload -> Handler -> Resource -> Twig.');
    }
}
```

The handler trusts the validated Payload and does not parse the raw request again. It does not build response arrays or render the template itself: the template is declared on the Resource, and the framework renders it after `handle()` returns. Services come in through `#[InjectAsReadonly]` properties, never through a constructor (see `HelloHandler` for an example).

If you renamed the generated classes to the `App\Modules\Website\` namespace, update the `namespace` and `use` lines in the two generated tests as well.

---

## Step 6: Restart and verify

```bash
bin/semitexa server:restart
```

Swoole workers keep discovered classes and compiled templates in memory for their whole life, so a running server does not see new routes or edited templates until it restarts.

Then open (use the port `server:start` printed):

- `http://localhost:9502/minimal?name=Ada` — the page, with "Hello, Ada".
- `http://localhost:9502/minimal?name=` — `422` with `{"errors":{"name":["Must be between 1 and 100 characters."]}}`.

`bin/semitexa routes:list` should list `/minimal`.

---

## If Something Goes Wrong

- **404**: the class must be under `src/modules/<Module>/src/`, its namespace must start with `App\Modules\<Module>\`, and the server must have been restarted.
- **500**: `bin/semitexa logs:app` shows the exception. "There are no registered paths for namespace" means the template extends or includes a Twig namespace that does not exist.
- **401** on a page you meant to be public: the payload uses `#[AsProtectedPayload]` (the `make:page` default). Switch to `#[AsPublicPayload]`.
- **Template change not visible**: `bin/semitexa server:restart`.

---

## Mapping

| Goal | Document or command |
|------|----------------------|
| Install and run app | [GET_STARTED.md](GET_STARTED.md) |
| JSON routes, discovery, custom 404 | the hub page `routing/adding-routes` |
| Module layout | the hub page `get-started/module-structure` · [MODULE_STRUCTURE.md](MODULE_STRUCTURE.md) |
| Payload validation | the hub page `validation/payload-validation` |
| Practical implementation rules | [AI_BEST_PRACTICES.md](AI_BEST_PRACTICES.md) |

---

## AI Quick Brief

1. `bin/semitexa make:module --name=<M> --target=custom --write`
2. `bin/semitexa make:page --module=<M> --name=<Page> --path=/<path> --method=GET --access=public --write`
3. Replace the generated template's `{% extends %}` with a layout that exists, e.g. `@project-layouts-theme-base/layouts/one-column.html.twig`, and put content in `{% block main %}`.
4. Namespace `App\Modules\<M>\`, code under `src/modules/<M>/src/Application/`; no `composer dump-autoload`.
5. Payload: `#[AsPublicPayload(path, methods, responseWith)]`, validation in setters via `ValidationException`.
6. Resource: extends `HtmlResponse`, implements `ResourceInterface`, `#[AsResource(handle, template)]`, typed `with*()` methods.
7. Handler: `#[AsPayloadHandler(payload, resource)]`, `final`, implements `TypedHandlerInterface`.
8. `bin/semitexa server:restart`, then open the route.
