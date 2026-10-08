---
id: routing/adding-routes
section: routing
slug: adding-routes
title: Adding Pages and Routes
summary: Creating a module and its first route end to end: JSON and HTML responses, where each class goes, how discovery finds it, a custom 404, and the usual mistakes.
order: 20
locale: en
status: canonical
verified_against: 2026.10.03.1952
keywords:
  - discovery
  - error.404
  - payload
  - handler
---

# Adding Pages and Routes

**Put new routes in modules** — `src/modules/<Module>/` in a project, or a package under `packages/` or `vendor/semitexa/`.

This is a convention, not a mechanical limit. `ClassDiscovery` merges every PSR-4 directory under `src/`, `tests/`, `packages/` and `vendor/semitexa/`, so a payload dropped straight into `src/` with an access attribute *will* be discovered and *will* answer requests. The reason to use a module anyway is that everything defining a route then lives in one predictable layout with a clear namespace, which is what the graph, the generators and the structure validator all read. A route class sitting loose in `src/` works and is invisible to all of them.

If you are looking for where the boundary actually is: it is the discovery roots above. Nothing outside them is scanned.

---

## Step-by-step: create a module and add a route

The starter `Hello` module (`src/modules/Hello/`) in a new project is a complete working example to compare against.

1. **Create the module**

   ```bash
   bin/semitexa make:module --name=Website --target=custom --write
   ```

   This creates `src/modules/Website/src/Application/...`, `src/modules/Website/src/Domain/...` and `src/modules/Website/tests/`. You can also create the directories by hand.

2. **Know how it is loaded**

   At boot, `LocalModuleAutoloadRegistrar` maps `App\Modules\Website\` to `src/modules/Website/src/` for every directory under `src/modules/`. There is no `composer dump-autoload` step and no root `composer.json` change. A module `composer.json` is optional; if you add one, use the same mapping:

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

3. **Create the Payload, Resource and Handler**

   HTTP request DTOs go in `src/Application/Payload/Request/`, response DTOs in `src/Application/Resource/Response/`, handlers in `src/Application/Handler/PayloadHandler/`. See [module structure](../get-started/module-structure.md) for the full layout.

   This example is a JSON endpoint, `GET /status`.

   `src/modules/Website/src/Application/Payload/Request/StatusPayload.php`:

   ```php
   <?php

   declare(strict_types=1);

   namespace App\Modules\Website\Application\Payload\Request;

   use App\Modules\Website\Application\Resource\Response\StatusResource;
   use Semitexa\Core\Attribute\AsPublicPayload;

   #[AsPublicPayload(path: '/status', methods: ['GET'], responseWith: StatusResource::class)]
   final class StatusPayload
   {
   }
   ```

   `src/modules/Website/src/Application/Resource/Response/StatusResource.php`:

   ```php
   <?php

   declare(strict_types=1);

   namespace App\Modules\Website\Application\Resource\Response;

   use Semitexa\Core\Attribute\AsResource;
   use Semitexa\Core\Contract\ResourceInterface;
   use Semitexa\Core\Http\Response\ResourceResponse;
   use Semitexa\Core\Http\Response\ResponseFormat;

   #[AsResource(format: ResponseFormat::Json)]
   final class StatusResource extends ResourceResponse implements ResourceInterface
   {
       public function withMessage(string $message): self
       {
           $this->setRenderContext(['message' => $message]);

           return $this;
       }
   }
   ```

   `src/modules/Website/src/Application/Handler/PayloadHandler/StatusHandler.php`:

   ```php
   <?php

   declare(strict_types=1);

   namespace App\Modules\Website\Application\Handler\PayloadHandler;

   use App\Modules\Website\Application\Payload\Request\StatusPayload;
   use App\Modules\Website\Application\Resource\Response\StatusResource;
   use Semitexa\Core\Attribute\AsPayloadHandler;
   use Semitexa\Core\Contract\TypedHandlerInterface;

   #[AsPayloadHandler(payload: StatusPayload::class, resource: StatusResource::class)]
   final class StatusHandler implements TypedHandlerInterface
   {
       public function handle(StatusPayload $payload, StatusResource $resource): StatusResource
       {
           return $resource->withMessage('Hello from the Website module');
       }
   }
   ```

   `GET /status` answers `{"message":"Hello from the Website module"}`. The payload is a plain class; the handler implements `TypedHandlerInterface` and takes the concrete payload and resource types.

   `#[AsPublicPayload]` makes a route anonymous. `#[AsProtectedPayload]` (signed-in user) and `#[AsServicePayload]` (machine credential) live in `Semitexa\Authorization\Attribute\`. A payload with none of the three is never routed.

4. **Restart the server**

   ```bash
   bin/semitexa server:restart
   ```

   Swoole workers keep discovered classes and compiled templates for their lifetime, so a new route appears only after a restart. `bin/semitexa routes:list` confirms it. Do **not** treat `bin/semitexa registry:sync` as a required manual step for ordinary payload changes.

---

## Responses: JSON and HTML pages

The example above returns JSON through a resource DTO marked `#[AsResource(format: ResponseFormat::Json)]`; its render context becomes the JSON body. For **HTML pages** the renderer is **`semitexa/ssr`**, which ships with the framework. Do not implement your own Twig renderer in the project.

**Steps for HTML pages:**

1. Create a resource that extends `Semitexa\Ssr\Application\Service\Http\Response\HtmlResponse` and carries `#[AsResource(handle: '...', template: '@project-layouts-<Module>/pages/thing.html.twig')]`.
2. Store templates in the module under `src/Application/View/templates/`; they are addressed through the Twig namespace `@project-layouts-<Module>`, not a filesystem path.
3. The handler fills the resource through typed `with*()` methods and returns it; the framework renders the template.

`bin/semitexa make:page --module=Website --name=Minimal --path=/minimal --method=GET --access=public --write` generates all four files in one step. Change one line of the generated template before you open the page: it extends `@layouts/base.html.twig`, which no package ships, so point it at a layout that exists, such as `@project-layouts-theme-base/layouts/one-column.html.twig`, and put the content in `{% block main %}`. The `Hello` module's `HelloResource` is a working example (`template: '@project-layouts-Hello/hello.html.twig'`).

**Detailed docs:** [rendering philosophy](../rendering/philosophy.md), [resource DTOs](../rendering/resource-dtos.md) and [slots](../rendering/slots.md). Do not put raw HTML in the handler and do not create a custom renderer — return a resource DTO.

If you need the public URL shape to be editable per environment without changing PHP code, see [env route override](env-route-override.md). `#[AsPublicPayload(path: 'env::VAR::/fallback')]` is the canonical pattern.

---

## Where to put Request/Handler

| Location | Discovered for routes? |
|----------|-------------------------|
| **Modules:** `src/modules/{ModuleName}/src/` (namespace `App\Modules\{ModuleName}\`) | Yes |
| **Packages:** project `packages/` (Semitexa packages with `composer.json`) | Yes |
| **Vendor:** installed packages (e.g. `vendor/semitexa/...`) | Yes |
| **Project `src/` (namespace `App\`), outside a module** | Yes — discovered, but invisible to the graph, generators and structure validator |

Place **all new routes** in a module (existing or new) under `src/modules/`, in `packages/`, or in an installed package. Loose classes still route, but they opt out of every tool that reads the module layout, so treat that as a mistake rather than a shortcut.

---

## How discovery works (architecture)

- **ModuleRegistry** finds modules in: `src/modules/` (every directory), project `packages/`, and `vendor/` (packages with `type: semitexa-module`).
- **LocalModuleAutoloadRegistrar** registers the PSR-4 mapping `App\Modules\<Name>\` → `src/modules/<Name>/src/` for each local module at boot. (`Semitexa\Modules\<Name>\` is mapped to the same directory for older code; new code uses `App\Modules\`.)
- **AttributeDiscovery** reads the attributes of every class in the PSR-4 directories merged by `ClassDiscovery`: `src/` (including the project `App\` namespace), `tests/`, `packages/`, and `vendor/semitexa/`. Module namespaces are the convention, not the filter.

---

## Custom 404 page (error.404 route)

If no route matches the request, or a handler throws `Semitexa\Core\Http\Exception\NotFoundException`, the system looks for a **named route** `error.404` (`RoutePhase::ROUTE_NAME_404`; the older `Application::ROUTE_NAME_404` is deprecated). If it exists, that route’s handlers run against the same request, so a module can render a custom 404 page. `semitexa/ssr` already ships one — route index `error.404`, payload `DefaultNotFoundPagePayload`.

- **Register a Payload** with `name: 'error.404'` and path/methods as needed (e.g. path `'/404'`, methods `['GET']`), plus a Response class and handler that render your 404 view.
- **Throw** `Semitexa\Core\Http\Exception\NotFoundException` in any handler when a resource is missing; the framework will then dispatch to the `error.404` route if registered, or return a plain 404 response.

---

## Common mistakes / FAQ

**My payload in `src/` routes, but no tooling sees it.**  
That is expected. `src/` is scanned, so the route works, but the module-structure validator, the project graph and the generators all key off the module layout. Move the class into `src/modules/{Module}/src/Application/...` under the `App\Modules\{Module}\` namespace and it rejoins them.

**I added a new Payload and Handler but the route doesn't exist (404)?**  
Check that the class is under `src/modules/<Module>/src/`, that its namespace starts with `App\Modules\<Module>\` and matches the directory, and that you ran `bin/semitexa server:restart`. `registry:sync` is a maintenance command, not the default fix for ordinary payload changes.

**Can I patch `AttributeDiscovery` to widen discovery?**  
Do not patch vendor. The discovery roots (`src/`, `tests/`, `packages/`, `vendor/semitexa/`) already cover every location a Semitexa project is expected to use.

## Summary

- **New pages and routes belong in modules** (`src/modules/`, `packages/`, or `vendor/semitexa/`) — not because loose classes fail to route, but because they drop out of every tool that reads the module layout.
- A local module is `src/modules/<Module>/src/Application/...` under `App\Modules\<Module>\`, mapped at boot by `LocalModuleAutoloadRegistrar`; no `composer dump-autoload`.
- Payloads are plain classes with an access attribute; handlers implement `TypedHandlerInterface`; responses are resource DTOs.
- **After adding or changing Payloads:** `bin/semitexa server:restart`. Use `registry:sync` only for maintenance flows explicitly documented by a package.
