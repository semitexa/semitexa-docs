---
id: routing/adding-routes
section: routing
slug: adding-routes
title: Adding Pages and Routes
summary: Creating a module and its first route end to end: JSON and HTML responses, where each class goes, how discovery finds it, a custom 404, and the usual mistakes.
order: 20
locale: en
status: canonical
keywords:
  - discovery
  - error.404
  - payload
  - handler
---

# Adding Pages and Routes

**Put new routes in modules** — `src/modules/`, `packages/`, or an installed package under `vendor/semitexa/`.

This is a convention, not a mechanical limit. `ClassDiscovery` merges every PSR-4 directory under `src/`, `tests/`, `packages/` and `vendor/semitexa/`, so a payload dropped straight into `src/` with an access attribute *will* be discovered and *will* answer requests. The reason to use a module anyway is that everything defining a route then lives in one predictable layout with a clear namespace, which is what the graph, the generators and the structure validator all read. A route class sitting loose in `src/` works and is invisible to all of them.

If you are looking for where the boundary actually is: it is the discovery roots above. Nothing outside them is scanned.

---

## Step-by-step: create a new module and add a route

1. **Create the module directory**  
   Example: `src/modules/Website/` (or `Api`, `Blog`, etc.).

2. **Add `composer.json` inside the module**  
   So the framework recognises it as a Semitexa module and registers its autoload:

   ```json
   {
     "name": "semitexa/module-website",
     "type": "semitexa-module",
     "autoload": {
       "psr-4": {
         "Semitexa\\Modules\\Website\\": "."
       }
     }
   }
   ```
   (Project root `composer.json` uses a single mapping `"Semitexa\\Modules\\": "src/modules/"` for all modules.)

   Run `composer dump-autoload` in the **project root** after adding or changing module `composer.json`.

3. **Create Request (Payload) and Handler in the module**  
   Put **HTTP request DTOs** in **`Application/Payload/Request/`** (namespace `Semitexa\Modules\{ModuleName}\Application\Payload\Request\`). Put **HTTP handlers** in **`Application/Handler/PayloadHandler/`**. See [module structure](../get-started/module-structure.md) for the full layout.

   **Example Request** — e.g. `src/modules/Website/Application/Payload/Request/HomePayload.php`:

   ```php
   <?php

   declare(strict_types=1);

   namespace Semitexa\Modules\Website\Application\Payload\Request;

   use Semitexa\Core\Attribute\AsPublicPayload;
   use Semitexa\Core\Contract\PayloadInterface;
   use Semitexa\Modules\Website\Application\Resource\HomeResource;

   #[AsPublicPayload(path: '/', methods: ['GET'], responseWith: HomeResource::class)]
   class HomePayload implements PayloadInterface
   {
   }
   ```

   **Example Handler** — e.g. `src/modules/Website/Application/Handler/PayloadHandler/HomeHandler.php`:

   ```php
   <?php

   declare(strict_types=1);

   namespace Semitexa\Modules\Website\Application\Handler\PayloadHandler;

   use Semitexa\Core\Attribute\AsPayloadHandler;
   use Semitexa\Core\Contract\PayloadInterface;
   use Semitexa\Core\Contract\ResourceInterface;
   use Semitexa\Core\Response;
   use Semitexa\Modules\Website\Application\Payload\Request\HomePayload;
   use Semitexa\Modules\Website\Application\Resource\HomeResource;

   #[AsPayloadHandler(payload: HomePayload::class, resource: HomeResource::class)]
   class HomeHandler
   {
       public function handle(PayloadInterface $request, ResourceInterface $response): ResourceInterface
       {
           return Response::json(['message' => 'Hello from Website module']);
       }
   }
   ```

   Use the **recommended** layout: **`Application/Payload/Request/`** for HTTP request DTOs, **`Application/Resource/`** for response DTOs, **`Application/Handler/PayloadHandler/`** for HTTP handlers, **`Application/View/templates/`** for Twig. See [module structure](../get-started/module-structure.md) for the canonical layout (payloads, event handlers, pipeline). The class must live under the **module namespace** (`Semitexa\Modules\Website\...`) and the module must have a valid `composer.json` with `"type": "semitexa-module"` and PSR-4 autoload.

   The example above returns JSON. **For HTML pages** use a Response DTO with a Twig template — see the section **"Responses: JSON and HTML pages"** below (or AI_REFERENCE / guides in semitexa/docs).

4. **Reload / clear stale runtime state if needed**  
   After adding or changing Request (Payload) classes, treat this as ordinary code changes: reload the app or restart the container if your runtime has not picked them up yet. Do **not** treat `bin/semitexa registry:sync` as a required manual step for ordinary payload changes.

5. **Reload**  
   Restart the app (e.g. `bin/semitexa server:stop` then `bin/semitexa server:start`) or ensure your runtime picks up the new classes; the framework will discover the new Request/Handler from the module.

---

## Responses: JSON and HTML pages

The step-by-step example above uses `Response::json([...])` — suitable for API endpoints. For **HTML pages** the renderer is **`semitexa/ssr`**, which ships with the framework. Do not implement your own Twig renderer in the project.

**Steps for HTML pages:**

1. Create a resource class carrying `#[AsResource(handle: '...', template: '@namespace/pages/thing.html.twig')]`.
2. Store templates in the module under `Application/View/templates/`; they are addressed through the Twig namespace, not a filesystem path.
3. The handler fills the resource context and returns it; `LayoutRenderer` renders the template.

A working example is `Semitexa\Demo\Application\Resource\Response\DemoFeatureResource`, which declares
`template: '@project-layouts-semitexa-demo/pages/feature.html.twig'`.

**Recommended stack:** for HTML apps, `semitexa/core` plus `semitexa/ssr`.

**Detailed docs:** [rendering philosophy](../rendering/philosophy.md), [resource DTOs](../rendering/resource-dtos.md) and [slots](../rendering/slots.md). Do not put raw HTML in the handler and do not create a custom renderer — return a resource DTO.

If you need the public URL shape to be editable per environment without changing PHP code, see [env route override](env-route-override.md). `#[AsPublicPayload(path: 'env::VAR::/fallback')]` is the canonical pattern.

---

## Where to put Request/Handler

| Location | Discovered for routes? |
|----------|-------------------------|
| **Modules:** `src/modules/{ModuleName}/` (with `composer.json` `type: semitexa-module`) | Yes |
| **Packages:** project `packages/` (Semitexa packages with `composer.json`) | Yes |
| **Vendor:** installed packages (e.g. `vendor/semitexa/...`) | Yes |
| **Project `src/` (namespace `App\`), outside a module** | Yes — discovered, but invisible to the graph, generators and structure validator |

Place **all new routes** in a module (existing or new) under `src/modules/`, in `packages/`, or in an installed package. Loose classes in the project root still route, but they opt out of every tool that reads the module layout, so treat that as a mistake rather than a shortcut.

---

## How discovery works (architecture)

- **ModuleRegistry** finds modules in: `src/modules/`, project `packages/`, and `vendor/` (packages with `type: semitexa-module` or under `vendor/semitexa/`).
- **IntelligentAutoloader** and **AttributeDiscovery** scan every PSR-4 directory merged by `ClassDiscovery`: `src/` (including the project `App\` namespace), `tests/`, `packages/`, and `vendor/semitexa/`. Module namespaces are the convention, not the filter.
- So to add new routes you must have a **module** with a proper `composer.json` and PSR-4 (root: `Semitexa\Modules\` → `src/modules/`; per-module e.g. `Semitexa\Modules\Website\` → `.`). Adding `App\Request\*` / `App\Handler\*` in project `src/` is not a supported way to register routes.

---

## Custom 404 page (error.404 route)

If no route matches the request, or a handler throws `Semitexa\Core\Http\Exception\NotFoundException`, the system looks for a **named route** `error.404` (`RoutePhase::ROUTE_NAME_404`; the older `Application::ROUTE_NAME_404` is deprecated). If it exists, that route’s handlers run against the same request, so a module can render a custom 404 page. `semitexa/ssr` already ships one — route index `error.404`, payload `DefaultNotFoundPagePayload`.

- **Register a Payload** with `name: 'error.404'` and path/methods as needed (e.g. path `'/404'`, methods `['GET']`), plus a Response class and handler that render your 404 view.
- **Throw** `Semitexa\Core\Http\Exception\NotFoundException` in any handler when a resource is missing; the framework will then dispatch to the `error.404` route if registered, or return a plain 404 response.

---

## Common mistakes / FAQ

**My payload in `src/` routes, but no tooling sees it.**  
That is expected. `src/` is scanned, so the route works, but the module-structure validator, the project graph and the generators all key off the module layout. Move the class into `src/modules/{Module}/` with a `composer.json` (`"type": "semitexa-module"` and PSR-4 autoload) and it rejoins them.

**I added a new Payload and Handler but the route doesn't exist (404)?**  
First verify the class lives inside a discovered module, the namespace matches the module PSR-4 mapping, and the app/container was reloaded after the change. `registry:sync` is a maintenance command, not the default fix for ordinary payload changes.

**Can I patch `IntelligentAutoloader` or `AttributeDiscovery` to widen discovery?**  
Do not patch vendor. The discovery roots (`src/`, `tests/`, `packages/`, `vendor/semitexa/`) already cover every location a Semitexa project is expected to use.

## Summary

- **New pages and routes belong in modules** (`src/modules/`, `packages/`, or `vendor/semitexa/`) — not because loose classes fail to route, but because they drop out of every tool that reads the module layout.
- Each module: directory, `composer.json` with `"type": "semitexa-module"` and PSR-4 (e.g. `Semitexa\Modules\Website\` → `.`); root has `Semitexa\Modules\` → `src/modules/`. Then Request/Handler classes with route attributes in that namespace.
- **After adding or changing Payloads:** reload the running app if needed. Use `registry:sync` only for maintenance flows explicitly documented by a package.
