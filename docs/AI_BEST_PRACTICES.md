# Semitexa Framework — Best Practices

This is the practical Semitexa playbook.

Use it when you do not want "possible" patterns. Use it when you want the patterns that keep the codebase legible, strict, and scalable under both human and AI-driven development.

All examples below are aligned with the current framework conventions and source APIs.

The spirit of this document is simple:

- choose one clear way where possible
- make structure visible
- make contracts explicit
- avoid patterns that force future readers to guess

## What This Document Is For

Use this document when:

- you are implementing a new feature and want the Semitexa-default approach
- you are unsure which of several possible patterns is the right one
- you want the codebase to stay understandable under long-term growth
- you want both humans and AI agents to reach the same architectural conclusion

Use package reference when you need exact API detail. Use this guide when you need the right architectural move.

## The Default Semitexa Stance

If you are unsure, bias toward these defaults:

- payloads define request contracts
- handlers populate resources
- resources define output shape
- modules own routes and application behavior
- explicit structure beats convenience magic
- one clear path beats multiple "valid" alternatives

This document exists to make those defaults operational.

---

## Before You Begin (read this first)

Before you write code, read this. It is the cheapest way to avoid the most common mistakes AI agents make in this repository.

### Where the canonical instructions live

- `AGENTS.md` (repo root) is the **operator manual**. Read it cold-start every session. `AGENTS_DOCTRINE.md` holds full flows / lifecycle / hygiene detail and is consulted on cross-reference.
- `packages/semitexa-docs/docs/` is the **canonical framework documentation**. This file lives there. Treat its siblings (`AI_GUIDE.md`, package guides, etc.) as authoritative for framework behavior.
- `packages/<pkg>/docs/` (when present) is canonical *package-local* documentation — owned by the package, narrower scope.
- `var/docs/` is **scratch only**. Audits, reports, planning notes, design drafts go there. Nothing in `var/docs/` is authoritative — treat it as in-flight working notes, not framework truth.
- Project root (`README.md`, `AGENTS.md`, `CLAUDE.md`, `AI_*.md`) is reserved for the framework scaffold. Do **not** add new root-level `.md` files for ad-hoc reports — write them to `var/docs/<slug>.md` instead.

### Where the backlog lives

Ideas, tasks, and status are tracked by `ai:epic` (in `var/ai-work/epics/`) and `ai:work` (in `var/ai-work/tasks/`). Documents are never the backlog. If a feature is "planned" but not in `ai:epic`, it does not exist as committed work — propose capturing it before treating it as real.

### How to run tests

The only blessed test entry point is the containerized command:

```bash
bin/semitexa test:run                           # full suite
bin/semitexa test:run tests/Unit/SomeTest.php   # narrowed path
bin/semitexa test:run -- --filter someTest      # extra phpunit args after a single double-dash
```

Do **not** run `vendor/bin/phpunit` directly. The container guarantees the correct PHP/Swoole/extension surface; running phpunit on the host produces unreliable results and is not how CI executes.

### Avoid inventing architecture

If a class, attribute, command, or path is not in the repository today, do not document it as if it were. Aspirational behavior gets captured as an `ai:epic`, not as best-practices guidance. When you are uncertain about whether a piece of architecture exists, search before you write — `ai:ask`, `ai:review-graph:query`, or a targeted `Grep` is cheaper than a wrong claim.

If a feature is referenced in this guide but verification at the time of reading shows it is missing or moved, prefer the repository over the guide and propose an update.

---

## Table of Contents

1. [Project Structure](#1-project-structure)
2. [Payload DTOs](#2-payload-dtos)
3. [Resource DTOs](#3-resource-dtos)
4. [Handlers](#4-handlers)
5. [Pipeline Listeners](#5-pipeline-listeners)
6. [Dependency Injection](#6-dependency-injection)
7. [Events](#7-events)
8. [Modules](#8-modules)
9. [Service Contracts](#9-service-contracts)
10. [Configuration](#10-configuration)
11. [Validation](#11-validation)
12. [Routing & URL Generation](#12-routing--url-generation)
13. [Templates & SSR](#13-templates--ssr)
14. [Tenancy](#14-tenancy)
15. [Locale & i18n](#15-locale--i18n)
16. [Queue & Async Handlers](#16-queue--async-handlers)
17. [Testing](#17-testing)
18. [Security](#18-security)
19. [Swoole & Coroutine Safety](#19-swoole--coroutine-safety)
20. [Error Handling](#20-error-handling)
21. [PHP Code Style](#21-php-code-style)
22. [Anti-Patterns](#22-anti-patterns)

---

## 1. Project Structure

Project structure is not cosmetic in Semitexa. It is part of the framework contract.

If the tree is clean, readers can predict behavior before opening files. If the tree drifts, both humans and AI lose confidence quickly.

> **Authoritative spec:** the on-disk shape of a module is defined in [`MODULE_STRUCTURE.md`](MODULE_STRUCTURE.md). That document is enforced by the `module_structure` check inside `bin/semitexa ai:verify` — drift is a hard failure.
>
> Section 1 of this file is the human-readable summary. If the two ever disagree, `MODULE_STRUCTURE.md` wins, and the discrepancy is a defect to be reported.

### Package layout

```text
packages/semitexa-{name}/
  src/
    Attributes/           # Package-specific attributes
    Application/
      Payload/
        Request/          # PayloadDTOs (#[AsPayload])
        Event/            # Event classes
        Part/             # #[AsPayloadPart] traits
      Resource/           # Render ResourceDTOs
        Response/         # Page/HTTP ResourceDTOs
        Slot/             # Slot ResourceDTOs (#[AsSlotResource])
      Handler/
        PayloadHandler/   # #[AsPayloadHandler] classes
        SlotHandler/      # #[AsSlotHandler] classes
        DomainListener/   # #[AsEventListener] classes
      Service/            # #[SatisfiesServiceContract] implementations
      Static/             # Static assets (manifest-driven)
        css/
        js/
        assets.json       # Asset manifest (v2, required)
      View/
        templates/        # Twig templates
      Component/          # #[AsComponent] classes
    Domain/
      Model/              # Domain entities
      Repository/         # Repository interfaces
      Contract/           # Interfaces
      Exception/          # Domain exceptions
    Context/              # Coroutine/request context stores
    Configuration/        # Readonly config classes
composer.json             # type: "semitexa-module"
```

Why this matters:

- a reader should infer system shape from the tree
- packages should not hide core behavior in arbitrary locations
- AI should not need folklore to understand where things belong

### Application module layout

Application modules under `src/modules/` follow the same structure as packages. Static assets live inside `Application/Static/` and are discovered via a required v2 `assets.json` manifest.

```text
src/modules/{ModuleName}/
  Application/
    Payload/
      Request/
      Event/
    Resource/
      Response/
      Slot/
    Handler/
      PayloadHandler/
      SlotHandler/
      DomainListener/
    Console
      Command/            # Console commands (#[AsCommand]) — see §1.1
    Update/               # Post-schema data patches (#[AsDataPatch]) — see §1.2
    Service/
    Static/               # Static assets (manifest-driven)
      css/
      js/
      assets.json         # Required v2 manifest (include rules)
    View/
      locales/
      templates/
        pages/            # Page-level templates (one per route)
        layouts/          # Layout templates (extend chains)
        partials/         # Reusable fragments
        components/       # Component-specific templates
        deferred/         # Templates for deferred/async slots
    Component/
  Domain/
    Model/
    Repository/
    Contract/
    Service/
  composer.json
```

Semitexa is strongest when application modules and packages feel like the same architectural language.

### 1.1 Console command placement

Console commands are first-class module artifacts, discovered the same way handlers are: by attribute scan over the composer classmap.

**Where they go:**

| Location | Path | Namespace |
|---|---|---|
| App module | `src/modules/{Module}/Application/Command/{Name}Command.php` | `Semitexa\Modules\{Module}\Application\Command` |
| Package | `packages/semitexa-{pkg}/src/Console/Command/{Name}Command.php` | `Semitexa\{Pkg}\Console\Command` |

**Discovery:** any class carrying `#[AsCommand(name: '...', description: '...')]` from `Semitexa\Core\Attribute\AsCommand` is registered with the framework console kernel. There is no manual wiring step.

**Scaffold the right shape:**

```bash
bin/semitexa make:command --module=Catalog --name=Reindex --command-name=catalog:reindex --description='Rebuild the catalog search index.'
```

**Canonical example** (illustrative module-owned command):

```php
// src/modules/Catalog/Application/Command/CatalogReindexCommand.php
namespace Semitexa\Modules\Catalog\Application\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\BaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'catalog:reindex', description: 'Rebuild the catalog search index.')]
final class CatalogReindexCommand extends BaseCommand
{
    public function __construct(
        private readonly CatalogIndexer $indexer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip confirmation.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        // …
        return self::SUCCESS;
    }
}
```

**Rules:**

- **Do** name commands with a `module:verb` form (`catalog:reindex`, `update:plan`, `theme:scaffold`).
- **Do** extend `Semitexa\Core\Console\BaseCommand` so the framework can wire DI cleanly.
- **Do** use Symfony Console primitives (`InputInterface`, `OutputInterface`, `SymfonyStyle`) inside `execute()`.
- **Do** put destructive commands behind explicit `--yes` / `--force` and refuse to run outside dev environments unless the operator has confirmed.
- **Don't** put commands in random locations like `src/{Module}/Cli/`, `bin/`, `scripts/`, or the project's `App\` namespace — discovery walks `Application/Command/` (modules) and `src/Console/Command/` (packages).
- **Don't** wire commands into `bin/semitexa` by hand or register them in a global list; the attribute is the registration.
- **Don't** call out to `bin/semitexa` from inside another command (process-fork loop) — depend on the underlying service or invoke a command class directly through the kernel if absolutely necessary.

### 1.2 Post-schema data patch placement

Data patches sit on top of the ORM schema. They never change the schema. Schema changes go through `semitexa-orm` (`orm:diff` / `orm:sync`); `semitexa-update` runs the post-schema data work that depends on the new schema being live.

**Where they go:**

| Location | Path (suggested) | Namespace (suggested) |
|---|---|---|
| App module | `src/modules/{Module}/Application/Update/{PatchName}.php` | `Semitexa\Modules\{Module}\Application\Update` |
| Package | `packages/semitexa-{pkg}/src/Update/{PatchName}.php` | `Semitexa\{Pkg}\Update` |

> Discovery is namespace-agnostic — `DataPatchDiscovery` walks every class in the composer classmap that carries `#[AsDataPatch]`. The paths above are the convention. Following them keeps patches alongside the rest of the module's `Application/` artifacts; placing them anywhere reachable by autoload still works but loses the structural cue.

**The contract:**

```php
namespace Semitexa\Modules\Catalog\Application\Update;

use Semitexa\Modules\Catalog\Domain\Model\Article;
use Semitexa\Update\Attribute\AsDataPatch;
use Semitexa\Update\Context\DataPatchContext;
use Semitexa\Update\Contract\DataPatchInterface;
use Semitexa\Update\Enum\UpdatePhase;

#[AsDataPatch(
    id: 'backfill-article-slugs',     // per-module stable id; MUST NOT change once shipped
    module: 'acme/catalog',           // composer package name — identity is (module, id)
    phase: UpdatePhase::Apply,        // Pre / Apply / Post relative to ORM schema sync
    dependencies: [],                 // ['acme/catalog:create-default-tags', ...]
    requires: [Article::class],       // entities the patch reads/writes; runner verifies the table is live
    description: 'Backfill slugs for legacy articles missing one.',
)]
final class BackfillArticleSlugs implements DataPatchInterface
{
    public function apply(DataPatchContext $ctx): void
    {
        // Idempotent — re-runs after a crash must not corrupt data.
        $ctx->execute("UPDATE articles SET slug = LOWER(REPLACE(title, ' ', '-')) WHERE slug IS NULL");
    }
}
```

**How patches are discovered, gated, and run:**

- Identity is `module:id`, not the FQCN — class renames do not re-run a patch.
- The runner sweeps in three phases per ORM run: **Pre patches → ORM schema sync → Apply patches → Post patches**, abort on first failure.
- Within a phase, patches resolve through a DAG built from `dependencies`.
- The schema-compatibility gate inspects the live DB before each patch. If a `requires` entity's table or a `requiresColumns` column is missing, the patch is **skipped** (not failed) and journaled separately — fix the schema with `orm:sync` first.
- `DataPatchContext::execute()` and `query()` reject SQL whose first non-comment token is `CREATE`, `ALTER`, `DROP`, `RENAME`, or `TRUNCATE` — `PatchSafetyException` fires. DDL belongs to ORM.
- State is persisted in a per-database journal — `pending`, `applied`, `failed`, `skipped`.

**CLI commands:**

| Command | What it does |
|---|---|
| `bin/semitexa update` | Run pending patches in phase + DAG order. `--dry-run` prints the plan. |
| `bin/semitexa update:plan` | Show pending patches without applying. |
| `bin/semitexa update:status` | Counts by phase: applied, pending, failed, skipped. |
| `bin/semitexa update:lint:patches` | Static validation of `#[AsDataPatch]` declarations. |

**Rules:**

- **Do** make patches idempotent — they may re-run after a partial failure.
- **Do** declare `requires` (entity FQCNs) and/or `requiresColumns` (table → columns map) so the schema gate can protect the patch.
- **Do** keep `id` stable for the lifetime of the patch — once shipped, never rename it; the journal keys on `module:id`.
- **Do** use `dependencies: ['<other-module>:<other-id>', …]` when the patch must follow another patch.
- **Don't** issue DDL inside a patch — `CREATE`/`ALTER`/`DROP`/`RENAME`/`TRUNCATE` are blocked at runtime and rejected by the lint command.
- **Don't** rely on `class FQCN` matching — the runner does not care what the class is named, only `(module, id)`.
- **Don't** use this attribute for ad-hoc maintenance scripts — those are console commands. Patches are part of the deploy/upgrade lifecycle.

### Theme directory

Themes are manifest-driven (see §13 for the full theme + skin model). The override surface for templates and assets lives at:

```text
src/theme/{theme-id}/
  theme.json              # Manifest: id, extends, active_when, skin
  {parent-theme-id}/
    templates/            # Sparse template overrides (same relative paths as parent)
      layouts/
      pages/
      partials/
    Static/               # Sparse asset overrides (same relative paths)
      css/
      js/
```

Only override files that differ from the parent. Resolution chains through `extends` — when the theme does not override a template, lookup falls back to the parent theme, and ultimately to the module that owns the template.

A theme is **activated per request** based on its `active_when` rules in `theme.json` (typically domain-gated). There is no `THEME` env var for selection — that mechanism has been replaced by manifest matching.

### Rules

- **Do** follow the directory convention strictly. The framework auto-discovers classes by namespace and attribute.
- **Do** place your module under `packages/` (local packages) or `src/modules/` (app modules).
- **Do** ensure `composer.json` declares `"type": "semitexa-module"`.
- **Do** place page response DTOs in `Application/Resource/Response/`.
- **Do** place slot resource DTOs in `Application/Resource/Slot/`.
- **Do** place slot hydration logic in `Application/Handler/SlotHandler/`.
- **Do** place console commands in `Application/Command/` (modules) or `src/Console/Command/` (packages) — see §1.1.
- **Do** place data patches in `Application/Update/` (modules) or `src/Update/` (packages) — see §1.2.
- **Do** place static assets in `Application/Static/` for both app modules and packages.
- **Do** use the canonical `templates/` subdirectories: `pages/`, `layouts/`, `partials/`, `components/`, `deferred/`.
- **Don't** place business logic outside `Application/` or `Domain/` directories.
- **Don't** create utility classes in the root `src/` of a package — use `Service/`, `Contract/`, etc.
- **Don't** put a `resources/` directory at module root — assets live under `Application/Static/`.
- **Don't** list every asset file manually; use `assets.json` include rules.
- **Don't** scatter console commands into `bin/`, `scripts/`, or top-level `App\` — discovery only walks the canonical command paths.

If a future contributor cannot guess where a feature belongs from the directory tree, the structure is already losing value.

---

## 2. Payload DTOs

A Payload DTO represents an incoming request. It is a plain PHP class with `#[AsPayload]`.

This is where Semitexa starts to feel different from loosely structured frameworks.

The payload is not just a DTO. It is the point where the request becomes explicit.

### Minimal GET payload

```php
#[AsPayload(path: '/demo/orm', methods: ['GET'], responseWith: OrmPageResource::class)]
class OrmIndexPayload {}
```

### POST payload with validation

```php
#[AsPayload(
    path: '/api/platform/users',
    methods: ['POST'],
    responseWith: GenericResponse::class,
)]
class UserCreatePayload implements ValidatablePayload
{
    use NotBlankValidationTrait;
    use EmailValidationTrait;
    use LengthValidationTrait;

    protected string $email = '';
    protected string $name  = '';
    protected string $password = '';

    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): void { $this->email = $email; }
    // ... other getters/setters ...

    public function validate(): PayloadValidationResult
    {
        $errors = [];
        $this->validateNotBlank('email', $this->email, $errors);
        $this->validateEmail('email', $this->email, $errors);
        $this->validateNotBlank('name', $this->name, $errors);
        $this->validateNotBlank('password', $this->password, $errors);
        $this->validateLength('password', $this->password, 8, null, $errors);
        return new PayloadValidationResult(empty($errors), $errors);
    }
}
```

### Path parameters with regex constraints

```php
#[AsPayload(
    path: '/api/platform/users/{id}',
    methods: ['GET'],
    responseWith: GenericResponse::class,
    requirements: ['id' => '[a-f0-9\\-]{36}'],
)]
class UserGetPayload
{
    public string $id = '';
    public function setId(string $id): void { $this->id = $id; }
}
```

### Environment variable interpolation in attributes

```php
#[AsPayload(
    path: 'env::API_LOGIN_PATH::/api/login',
    methods: ['POST'],
    name: 'env::API_LOGIN_ROUTE_NAME::api.login',
)]
```

Syntax: `env::VAR_NAME::default_value`.

### Response formats and alternate representations

`produces` belongs on the payload DTO because the payload owns the route contract.

```php
#[AsPayload(
    path: '/products',
    methods: ['GET'],
    responseWith: ProductPageResource::class,
    produces: ['text/html', 'application/json'],
)]
final class ProductPagePayload
{
}
```

Use this when one route intentionally serves multiple representations of the same resource.

What Semitexa does with `produces`:

- negotiates the response format from `Accept` or `?_format=...`
- keeps the route contract explicit in one place
- on SSR pages, emits `<link rel="alternate" type="...">` tags for the non-HTML variants declared by the current payload DTO

What not to do:

- do not add `application/json` just because a JSON serializer exists somewhere in the stack
- do not hardcode alternate links in Twig when the payload DTO already defines the formats
- do not declare alternate formats that the handler/resource pair does not actually support

### Rules

- **Do** always provide `responseWith:` — it links the payload to its resource.
- **Do** implement `ValidatablePayload` for any payload that accepts user input.
- **Do** use validation traits (`NotBlankValidationTrait`, `EmailValidationTrait`, `LengthValidationTrait`) rather than inline validation.
- **Do** use `protected` properties with explicit getters/setters. The `RequestDtoHydrator` calls setters to populate the DTO.
- **Do** use `requirements:` for path params — it constrains what the router will match.
- **Don't** implement `PayloadInterface` — it is deprecated (v2.0). Payloads no longer need a marker interface; the pipeline accepts `object` typed parameters.
- **Don't** put business logic in a payload. It is a data carrier only.
- **Don't** use constructor params for request data — the framework instantiates the DTO then calls setters.
- **Don't** make properties `readonly` — the hydrator needs to set them via setters.

The handler should not have to wonder whether the input is trustworthy. That decision belongs here.

---

## 3. Resource DTOs

A Resource DTO is the **typed output contract** of the handler pipeline. It declares the shape of the response — before any handler runs. The framework instantiates the Resource DTO (using `responseWith:` from the Payload attribute), passes it through the pipeline, and converts it to an HTTP response at the end.

If payloads make input explicit, resources make output explicit.

That symmetry is one of the main reasons Semitexa remains understandable at scale.

The Resource DTO is the single object that travels through the entire request lifecycle:

```text
Payload attribute (responseWith: HomepageResource::class)
  → Framework instantiates HomepageResource
    → #[AsResource] attribute read (cached): setRenderHandle(), declaredTemplate stored
      → Pipeline listeners receive it via $context->resourceDto
        → Handler receives it as the second argument, populates it, returns it
          → Next handler in group receives the same instance
            → toCoreResponse(): if content empty, renderTemplate(declaredTemplate) auto-called
              → Twig renders page template ({% extends layout %}) → full HTML
                → Framework converts to HTTP response
```

Handlers never construct responses — they populate the Resource DTO. The framework handles serialization, rendering, and HTTP conversion.

---

### GenericResponse — the base class

`GenericResponse` is the base Resource DTO. It implements `ResourceInterface` and `LayoutRenderableInterface` and provides the full response API:

```php
// Context data — serialized to JSON for API responses
$resource->setContext(['user' => $user->toArray()]);
$resource->getContext(); // returns the array

// Aliases (equivalent to setContext/getContext):
$resource->setRenderContext(['key' => 'value']);
$resource->getRenderContext();

// Redirect
$resource->setRedirect('/dashboard');
$resource->setRedirect('/new-url', HttpStatus::MovedPermanently->value);

// Raw content (for binary, raw HTML, custom formats)
$resource->setContent('<html>...</html>');
$resource->getContent(); // returns current content (empty string if not set)

// HTTP headers
$resource->setHeader('Content-Type', 'application/pdf');
$resource->setHeader('X-Custom-Header', 'value');

// HTTP status (default: 200)
$resource->setStatusCode(HttpStatus::Created->value);

// Layout orchestration hints (used by LayoutRenderer)
$resource->setRenderHandle('homepage');    // selects layout slots for this page
$resource->setLayoutFrame('two-columns'); // optional layout frame variant
$resource->setRendererClass(CustomRenderer::class); // custom renderer override
```

**For JSON API endpoints, use `GenericResponse` directly** — no custom class needed:

```php
#[AsPayload(path: '/api/users/{id}', methods: ['GET'], responseWith: GenericResponse::class)]
class UserGetPayload { ... }

// In handler:
$resource->setContext(['id' => $user->id, 'email' => $user->email]);
```

---

### HtmlResponse — SSR subclass

`HtmlResponse` extends `GenericResponse` and is the base for all SSR page Resources. It adds:

```php
// SEO — fluent, chainable
$resource->pageTitle('Catalog');                          // wraps SeoMeta::setTitle()
$resource->seoTag('description', 'Browse products');      // wraps SeoMeta::tag()
$resource->seoTag('og:title', 'Catalog');

// Render Twig template — uses $this->renderContext automatically
$resource->renderTemplate('@project-layouts-Catalog/pages/list.html.twig');

// Render inline Twig string — uses $this->renderContext automatically
$resource->renderString('<h1>{{ title }}</h1>');
```

Context is never passed as an array to `renderTemplate()`. It is pre-populated via typed `with*()` methods defined on the Resource subclass (see below).

`HtmlResponse` also exposes a `protected with(string $key, mixed $value): self` helper — used exclusively by Resource subclass methods to populate `$renderContext`.

`HtmlResponse` sets `Content-Type: text/html; charset=UTF-8` automatically.

---

### Custom Resource classes — SSR pages

Create a custom Resource class for every SSR page. Place it in `Application/Resource/Response/`. Use `#[AsResource]` to declare:
- `handle` — the layout identity. Used to inject `page_handle` and `layout_handle` into Twig context, enabling `{{ layout_slot() }}` calls, driving deferred rendering and SSE.
- `template` — the page body Twig template. When set, the handler does not need to call `renderTemplate()` — the framework calls it automatically.

The framework reads `#[AsResource]` at instantiation (once per class, cached in a static array). It calls `setRenderHandle()` with `handle` and stores `template` as `$declaredTemplate`. After all handlers complete, `toCoreResponse()` calls `renderTemplate($declaredTemplate)` automatically if no content was set. The page template uses `{% extends %}` Twig inheritance to pull in the layout — no explicit `LayoutRenderer` call needed from the handler.

```php
// Application/Resource/Response/CatalogListResource.php
#[AsResource(
    handle: 'catalog_list',
    template: '@project-layouts-Catalog/pages/list.html.twig',
)]
class CatalogListResource extends HtmlResponse implements ResourceInterface
{
    public function withPageTitle(string $title): self
    {
        return $this->with('pageTitle', $title);
    }

    public function withProducts(array $products): self
    {
        return $this->with('products', $products);
    }

    public function withTotal(int $total): self
    {
        return $this->with('total', $total);
    }

    public function withFilters(array $filters): self
    {
        return $this->with('filters', $filters);
    }
}
```

```php
// Application/Payload/Request/CatalogListPayload.php
#[AsPayload(path: '/catalog', methods: ['GET'], responseWith: CatalogListResource::class)]
class CatalogListPayload { ... }
```

```php
// Application/Handler/PayloadHandler/CatalogListHandler.php
#[AsPayloadHandler(payload: CatalogListPayload::class, resource: CatalogListResource::class)]
final class CatalogListHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected ProductRepositoryInterface $products;

    public function handle(CatalogListPayload $payload, CatalogListResource $resource): CatalogListResource
    {
        $result = $this->products->paginate($payload->getPage());

        // No renderTemplate() call — the template is declared in #[AsResource] and
        // toCoreResponse() will render it automatically after all handlers complete.
        return $resource
            ->pageTitle('Catalog')
            ->withPageTitle('Products')
            ->withProducts($result->items)
            ->withTotal($result->total);
    }
}
```

Handlers never pass a context array to `renderTemplate()`. The Resource DTO accumulates context via typed `with*()` methods. `renderTemplate()` merges `$renderContext` with any `$extraContext` automatically.

**The `$renderHandle` is the link between the page Resource DTO and the slot system.** Every `#[AsSlotResource(handle: 'catalog_list', ...)]` registered in any module is associated with this handle. When `renderTemplate()` is called (explicitly or via auto-render), `page_handle` and `layout_handle` are injected into the Twig context — enabling `{{ layout_slot() }}` calls inside layout templates.

---

### Response Resources and slot rendering

When a page has layout slots, `#[AsResource(handle: '...')]` drives slot selection automatically. The handler populates page-level typed context via `with*()` methods. Independent layout blocks are hydrated through slot resources and slot handlers, not through the page handler.

```text
#[AsResource(handle: 'catalog_list', template: '@project-layouts-Catalog/pages/list.html.twig')]
CatalogListResource
  │
  │  toCoreResponse() → renderTemplate('@project-layouts-Catalog/pages/list.html.twig')
  │                      injects page_handle='catalog_list' into Twig context
  ▼
Twig renders page template ({% extends '@project-layouts-Catalog/layouts/base.html.twig' %})
  ├── Layout resolves matching #[AsSlotResource(...)] registrations
  ├── For each slot resource:
  │   ├── create slot DTO instance
  │   ├── execute #[AsSlotHandler] pipeline
  │   ├── collect clientModules
  │   └── render slot template
  ├── Deferred/live slots use the same slot pipeline
  └── The asset pipeline emits collected slot client modules into <head> (slot-specific exception: clientModules are loaded in <head>, unlike regular page JS which is body-positioned by convention)
```

The page handler does not hydrate independent slot blocks directly. Slot data belongs to slot resources and slot handlers.

---

### Slot Resource DTOs

Semitexa now distinguishes two resource categories:

- **Response Resources** in `Application/Resource/Response/`
- **Slot Resources** in `Application/Resource/Slot/`

Response resources are instantiated from `responseWith:` on payloads and travel through the request handler pipeline.

Slot resources are instantiated by the slot rendering system and travel through the slot handler pipeline.

Use a slot resource when all of the following are true:

- the data belongs to a renderable page region or layout block;
- the block may be reused or extended independently of the page handler;
- the block may need its own browser behavior via `clientModules`;
- the block should work the same way in sync, deferred, and live rendering.

Use a response resource when the data belongs to the page as a whole.

The rule is simple:

- page-level data belongs to response resources;
- block-level data belongs to slot resources.

---

### Resource DTO in handler groups

When multiple handlers are registered for the same `(Payload, Resource)` pair, they form a handler group. Each handler receives the resource returned by the previous handler. The resource accumulates state:

```php
// Handler 1: sets SEO title (fluent API on resource)
final class SetTitleHandler implements TypedHandlerInterface
{
    public function handle(CatalogListPayload $payload, CatalogListResource $resource): CatalogListResource
    {
        return $resource->pageTitle('Catalog');
    }
}

// Handler 2: populates data (receives same resource instance, auto-renders via toCoreResponse)
final class CatalogListHandler implements TypedHandlerInterface
{
    public function handle(CatalogListPayload $payload, CatalogListResource $resource): CatalogListResource
    {
        return $resource
            ->withPageTitle('Products')
            ->withTotal($this->repo->count());
    }
}
```

---

### Resource DTO in pipeline listeners

Pipeline listeners receive the Resource DTO via `$context->resourceDto` and can modify it before handlers run:

```php
#[AsPipelineListener(phase: AuthCheck::class)]
final class TenantThemeListener implements PipelineListenerInterface
{
    public function handle(RequestPipelineContext $context): void
    {
        // Read the render handle to apply theme logic
        $handle = $context->resourceDto->getRenderHandle();

        // Or modify the resource:
        $context->resourceDto->setLayoutFrame('minimal');
    }
}
```

---

### Choosing the right Resource class

| Scenario | Resource class |
|---|---|
| JSON API endpoint | `GenericResponse::class` (inline in `responseWith:`) |
| SSR page with layout slots | Custom class extending `HtmlResponse` in `Application/Resource/Response/` |
| SSR page with deferred or live slots | Same as above — `$renderHandle` drives slot orchestration automatically |
| Layout block / sidebar / widget | Custom class extending `HtmlSlotResponse` in `Application/Resource/Slot/` |
| Redirect (no page) | `GenericResponse::class`, handler calls `$resource->setRedirect(...)` |
| Binary / raw content | `GenericResponse::class`, handler calls `$resource->setContent(...)` + `setHeader(...)` |

---

### Rules

- **Do** create one custom `*Resource` class per SSR page type, placed in `Application/Resource/Response/`.
- **Do** always declare `#[AsResource(handle: '...', template: '...')]` on every `HtmlResponse` subclass — `handle` drives `LayoutRenderer` and slot resolution; `template` declares the page body.
- **Do** declare typed `with*()` methods on Resource subclasses — one per template variable. Call `$this->with('key', $value)` inside them.
- **Do** keep page response resources page-focused. Move block-local data into slot resources.
- **Do** call `->pageTitle()` and `->seoTag()` on the Resource — never call `SeoMeta::setTitle()` directly in handlers.
- **Do** use `GenericResponse::class` inline for API endpoints — no custom class needed.
- **Do** implement `ResourceInterface` on all custom resource classes.
- **Do** use `$resource->setContext()` for API data — the framework handles JSON serialization.
- **Do** use `$resource->setRedirect()` instead of constructing redirect responses manually.
- **Do** use `HttpStatus` enum values for all status codes — no magic integers.
- **Don't** declare `protected string $renderHandle` or `protected array $renderContext = []` in Resource subclasses — these are replaced by `#[AsResource]` and `with*()` methods.
- **Don't** call `renderTemplate()` from handlers when `template` is declared in `#[AsResource]` — `toCoreResponse()` renders it automatically. Call `renderTemplate()` explicitly only when the Resource has no declared template (e.g. it is shared across multiple handlers each rendering a different template).
- **Don't** pass a context array to `renderTemplate()` — pre-populate via `with*()` methods. Use `$extraContext` only for one-off keys not worth a dedicated method.
- **Don't** call `with()` directly from handlers — it is `protected`, for Resource methods only.
- **Don't** instantiate Resource DTOs manually in handlers — the framework creates them.
- **Don't** use `handle` for routing or branching logic in handler code — it is a layout identity, read only by `LayoutRenderer`.
- **Don't** hydrate independent sidebar/widget/footer blocks inside page handlers when they belong to slot resources.

When resources are treated as first-class output contracts, handlers become simpler and rendering stays predictable.

---

## 4. Handlers

Handlers implement `TypedHandlerInterface` and carry `#[AsPayloadHandler]`. They are **pure data processors** — they receive typed input, populate typed output, and throw domain exceptions. They never touch HTTP concepts (status codes, Response objects, serialization formats).

> **Note:** The legacy `HandlerInterface` is deprecated and will be removed in v2.0. All new handlers must use `TypedHandlerInterface`.

This is the heart of Semitexa application code.

When the architecture is healthy, handlers feel boring in the best possible way:

- no raw request parsing
- no response-object construction
- no transport-level branching
- no hidden framework ritual

That boringness is a feature. It means the important decisions were made earlier, in the payload and resource contracts, instead of being re-litigated in every handler.

### Basic handler (SSR page)

```php
// HomepageResource declares template via #[AsResource] — handler just sets title and data.
// toCoreResponse() renders the template automatically after the pipeline completes.
#[AsPayloadHandler(payload: HomepagePayload::class, resource: HomepageResource::class)]
final class HomepageHandler implements TypedHandlerInterface
{
    public function handle(HomepagePayload $payload, HomepageResource $resource): HomepageResource
    {
        return $resource
            ->pageTitle('Home')
            ->withDemoPages([...]);
    }
}
```

### Handler for a shared Resource (multiple templates)

When one Resource class is shared across handlers that render different page templates (e.g. `DemoResource`), each handler calls `renderTemplate()` explicitly with its own template path. Context is pre-populated via typed `with*()` methods — no inline array.

```php
#[AsPayloadHandler(payload: ComponentsPayload::class, resource: DemoResource::class)]
final class ComponentsHandler implements TypedHandlerInterface
{
    public function handle(ComponentsPayload $request, DemoResource $response): DemoResource
    {
        return $response
            ->pageTitle('Components Demo - Semitexa SSR')
            ->withPageTitle('Components')
            ->renderTemplate('@project-layouts-SsrDemo/pages/components.html.twig');
    }
}
```

### API handler with property injection

```php
#[AsPayloadHandler(payload: UserGetPayload::class, resource: GenericResponse::class)]
final class UserGetHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected UserRepositoryInterface $userRepo;

    public function handle(UserGetPayload $payload, GenericResponse $resource): GenericResponse
    {
        // Repository::find() throws NotFoundException → ExceptionMapper → 404
        $user = $this->userRepo->find($payload->id);

        $resource->setContext([
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'name' => $user->name,
            ],
        ]);
        return $resource;
    }
}
```

### Handler with service injection

```php
#[AsPayloadHandler(payload: EventsDispatchPayload::class, resource: GenericResponse::class)]
final class EventsDispatchHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected EventDispatcherInterface $eventDispatcher;

    public function handle(EventsDispatchPayload $payload, GenericResponse $resource): GenericResponse
    {
        $event = $this->eventDispatcher->create(DemoGreetingEvent::class, [
            'message' => 'Hello from Events demo',
        ]);
        $this->eventDispatcher->dispatch($event);

        $resource->setRedirect('/demo/events?dispatched=1');
        return $resource;
    }
}
```

### Handler with domain exceptions

```php
#[AsPayloadHandler(payload: SettingsSetPayload::class, resource: GenericResponse::class)]
final class SettingsSetHandler implements TypedHandlerInterface
{
    public function handle(SettingsSetPayload $payload, GenericResponse $resource): GenericResponse
    {
        if ($payload->getModuleKey() === '' || $payload->getKey() === '') {
            throw new ValidationException(['module_key' => ['module_key and key are required']]);
        }

        if (!$this->canManageGlobalSettings()) {
            throw new AccessDeniedException('Insufficient permissions.');
        }

        $this->settings->set($payload->getModuleKey(), $payload->getKey(), $payload->getValue());

        $resource->setContext(['ok' => true]);
        return $resource;
    }
}
```

### Handler groups (multiple handlers for one payload)

Multiple handlers registered for the same `(Payload, Resource)` pair form a **handler group**. They execute sequentially — each sync handler receives the resource returned by the previous one.

```php
// Handler 1: validate
#[AsPayloadHandler(payload: FileUploadPayload::class, resource: GenericResponse::class)]
final class ValidateFileHandler implements TypedHandlerInterface { ... }

// Handler 2: store (sync)
#[AsPayloadHandler(payload: FileUploadPayload::class, resource: GenericResponse::class)]
final class StoreFileHandler implements TypedHandlerInterface { ... }

// Handler 3: notify (async — enqueued, not blocking)
#[AsPayloadHandler(
    payload: FileUploadPayload::class,
    resource: GenericResponse::class,
    execution: HandlerExecution::Async,
)]
final class NotifyUploadHandler implements TypedHandlerInterface { ... }
```

### Response patterns

Handlers populate the resource DTO — they never construct `Response` objects:

```php
// JSON API — set context data (rendered to JSON by the framework)
$resource->setContext(['user' => $user->toArray()]);

// Redirect
$resource->setRedirect('/dashboard');
$resource->setRedirect('/new-url', HttpStatus::MovedPermanently->value);

// SSR HTML (on HtmlResponse) — auto-rendered via toCoreResponse() when template declared in #[AsResource]
// Call explicitly only when the resource has no declared template, or to override it:
$resource->renderTemplate($template);

// Raw content (binary files, inline HTML)
$resource->setContent($htmlOrBinary);
$resource->setHeader('Content-Type', 'text/html; charset=utf-8');

// Status code (default is 200)
$resource->setStatusCode(HttpStatus::Created->value);
```

### Error patterns

Handlers throw domain exceptions — the `ExceptionMapper` converts them to content-negotiated error responses:

```php
throw new NotFoundException('User', $id);             // → 404
throw new ValidationException(['email' => ['...']]);  // → 422
throw new AuthenticationException();                   // → 401
throw new AccessDeniedException('No permission.');     // → 403
throw new ConflictException('Email already exists.');  // → 409
throw new RateLimitException(retryAfter: 60);          // → 429
```

### Rules

- **Do** always mark handlers `final`.
- **Do** implement `TypedHandlerInterface` — never the deprecated `HandlerInterface`.
- **Do** use concrete Payload and Resource types in the `handle()` signature — no `instanceof` checks.
- **Do** throw `DomainException` subclasses for errors — never return `Response` objects.
- **Do** use `$resource->setContext()` for API data — the framework handles JSON/XML serialization.
- **Do** use `$resource->setRedirect()` instead of `Response::redirect()`.
- **Do** use `#[InjectAsReadonly]` for stateless services and `#[InjectAsMutable]` for request-scoped state.
- **Do** make optional dependencies nullable with a default of `null`.
- **Don't** call other handlers directly — use events or handler groups.
- **Don't** access `$_GET`, `$_POST`, `$_SERVER` — everything comes through the payload or injected `Request`.
- **Don't** use `echo` or `die()` — always return the resource DTO.
- **Don't** return `Response` objects from `TypedHandlerInterface` handlers — the pipeline rejects them with a `LogicException`.
- **Don't** use hardcoded HTTP status code integers — use the `HttpStatus` enum.

If handlers start feeling "smart," the architecture is usually leaking responsibility into the wrong layer.

---

## 5. Pipeline Listeners

Pipeline listeners provide cross-cutting middleware-like behavior. They run **before** handler groups.

Use pipeline listeners for system-wide concerns that should stay outside application handlers:

- authentication
- authorization
- tenancy guards
- request-shaping concerns

If a concern applies to many routes, it probably belongs here instead of being repeated in handlers.

### Two built-in phases (in order)

1. **`AuthCheck`** — authentication
2. **`HandleRequest`** — triggers handler group execution

Authorization now runs inside the `AuthCheck` phase via pipeline listeners such as
`AuthorizationListener`, so there is no separate `AccessCheck` phase anymore.

### Writing a pipeline listener

```php
#[AsPipelineListener(phase: AuthCheck::class, priority: 10)]
final class CustomAuthListener implements PipelineListenerInterface
{
    #[InjectAsReadonly]
    protected ?ContainerInterface $container = null;

    public function handle(RequestPipelineContext $context): void
    {
        // Access payload, resource, raw request, auth result:
        $payload   = $context->requestDto;
        $resource  = $context->resourceDto;
        $request   = $context->request;

        // Throw to short-circuit:
        throw new AuthenticationException('Not authenticated');
    }
}
```

### Rules

- **Do** use `priority` to control execution order within a phase (lower = earlier).
- **Do** throw `AuthenticationException` (→ 401) or `AccessDeniedException` (→ 403) to short-circuit. (Legacy names `AuthenticationRequiredException` / `Pipeline\Exception\AccessDeniedException` still work as deprecated aliases.)
- **Don't** implement middleware as a handler — use pipeline listeners for cross-cutting concerns.
- **Don't** create new phases unless absolutely necessary — extend existing ones with priority.

Handlers should focus on feature behavior. Pipeline listeners should protect and shape the request before the feature code begins.

---

## 6. Dependency Injection

Semitexa uses a two-tier DI model optimized for Swoole's long-running process.

Dependency injection in Semitexa is about lifecycle correctness as much as ergonomics.

In a long-lived runtime, the wrong object lifetime becomes a correctness bug, not just a style problem. The DI model exists to make those lifetimes explicit.

### Injection tiers

| Annotation | Scope | Lifecycle | Use for |
|---|---|---|---|
| `#[InjectAsReadonly]` | Per-worker (shared) | Built once, shared across all requests | Stateless services, repositories, config |
| `#[InjectAsMutable]` | Per-request (clone) | Prototype cloned per `get()`, request context injected | Session-scoped state, request-aware services |
| `#[InjectAsFactory]` | Per-resolve | Returns `ContractFactoryInterface` | Multi-implementation contracts |

### Property injection (preferred for handlers)

```php
#[AsPayloadHandler(payload: MyPayload::class, resource: GenericResponse::class)]
final class MyHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected UserRepositoryInterface $userRepo;

    #[InjectAsMutable]
    protected SessionInterface $session;

    #[InjectAsFactory]
    protected NotifierFactoryInterface $notifiers;

    public function handle(MyPayload $payload, GenericResponse $resource): GenericResponse
    {
        $user = $this->userRepo->find($payload->id);
        $this->session->set('last_viewed', $user->id);
        $notifier = $this->notifiers->get('email');
        // ...
    }
}
```

### Constructor injection (plain PHP objects only)

```php
readonly class OrderSummary
{
    public function __construct(
        public int $total,
        public int $pending,
    ) {}
}
```

For framework-managed classes, prefer Semitexa injection attributes. Constructor injection is best reserved for plain PHP objects, immutable helpers, and value objects that are not part of the framework-managed request lifecycle.

### Rules

- **Do** use `#[InjectAsReadonly]` by default — it's the most efficient (no cloning).
- **Do** use `#[InjectAsMutable]` only for services that hold per-request state (Session, CookieJar).
- **Do** make optional dependencies nullable: `protected ?SomeInterface $service = null`.
- **Do** prefer Semitexa injection attributes for framework-managed classes.
- **Don't** use `new` to instantiate services — always inject via DI.
- **Don't** store request-scoped data in `#[InjectAsReadonly]` services — they're shared across requests.
- **Don't** use constructor injection in handlers or other framework-managed request classes.

---

## 7. Events

### Define an event

```php
// Application/Payload/Event/OrderPlacedEvent.php
final class OrderPlacedEvent
{
    private string $orderId = '';
    private string $userId  = '';

    public function getOrderId(): string { return $this->orderId; }
    public function setOrderId(string $orderId): void { $this->orderId = $orderId; }
    public function getUserId(): string { return $this->userId; }
    public function setUserId(string $userId): void { $this->userId = $userId; }
}
```

### Register a listener

```php
// Application/Handler/DomainListener/OrderNotificationListener.php
#[AsEventListener(event: OrderPlacedEvent::class)]
final class OrderNotificationListener
{
    public function handle(OrderPlacedEvent $event): void
    {
        // Send notification, update analytics, etc.
    }
}
```

### Dispatch from a handler

```php
// Prefer create() over new Event(...) so Semitexa controls hydration.
$event = $this->eventDispatcher->create(OrderPlacedEvent::class, [
    'orderId' => $order->getId(),
    'userId'  => $user->getId(),
]);
$this->eventDispatcher->dispatch($event);
```

### Execution modes

| Mode | Behavior |
|---|---|
| `EventExecution::Sync` | Runs immediately in the same coroutine (default) |
| `EventExecution::Async` | Deferred via `Swoole\Event::defer()` when available; otherwise falls back to sync |
| `EventExecution::Queued` | Published to queue transport; if the queue is unavailable, Semitexa falls back to sync |

### Rules

- **Do** use events for side effects (notifications, logging, cache invalidation).
- **Do** keep event classes as simple hydratable data carriers with setters/getters or equivalent mutable fields.
- **Do** inject `EventDispatcherInterface` and use `create()` + `dispatch()` instead of manually constructing framework-dispatched events.
- **Don't** throw exceptions in event listeners — they shouldn't break the main flow.
- **Don't** rely on listener execution order unless you explicitly need it.

---

## 8. Modules

### Module registration

A module is any package with `"type": "semitexa-module"` in its `composer.json`:

```json
{
    "name": "vendor/my-module",
    "type": "semitexa-module",
    "version": "1.0.0",
    "extra": {
            "semitexa-module": {
            "extends": "ParentModuleName",
            "template_alias": "my-module",
            "template_paths": ["Application/View/templates"]
        }
    }
}
```

### Module hierarchy (`extends`)

The `extends` field defines parent-child relationships. `ModuleRegistry` topologically sorts modules so that child modules override parent contracts.

```text
ParentModule
  └─ ChildModule (extends: "ParentModule")
       └─ GrandchildModule (extends: "ChildModule")
```

When two modules provide the same service contract, the **child** wins.

### Discovery sources (by priority)

| Location | Priority | Namespace |
|---|---|---|
| `src/modules/{Name}/` | 400 | `Semitexa\Modules\{Name}` |
| `packages/` | 200 | Varies |
| `vendor/` | 100 | Varies |

### Rules

- **Do** declare `"type": "semitexa-module"` in `composer.json`.
- **Do** use `extends` to participate in contract resolution hierarchy.
- **Do** run `composer dump-autoload` after adding/removing classes (the framework reads the classmap).
- **Don't** create an `#[AsModule]` attribute class — module identity comes from `composer.json`.
- **Don't** treat project `src/` (`App\`) as the default place for route-bearing application code. New routes belong in modules.

---

## 9. Service Contracts

Service contracts are how Semitexa keeps extension explicit.

Instead of hiding substitution behind container magic, the framework makes contract implementation visible and resolvable. That matters even more when multiple modules compete to provide the same capability.

### Declaring a contract implementation

```php
#[SatisfiesServiceContract(of: SettingsStoreInterface::class)]
final class SettingsStore implements SettingsStoreInterface { ... }

#[SatisfiesRepositoryContract(of: UserRepositoryInterface::class)]
final class DoctrineUserRepository implements UserRepositoryInterface { ... }
```

### Generated registry resolvers

Use `bin/semitexa registry:sync:contracts` when you are working on resolver generation for multi-implementation contracts:

```php
// Auto-generated: src/registry/Contracts/SettingsStoreResolver.php
final class SettingsStoreResolver
{
    public function __construct(
        private SettingsStoreFromModuleA $implA,
        private SettingsStoreFromModuleB $implB,
    ) {}

    public function getContract(): SettingsStoreInterface
    {
        return $this->implB; // edit to switch
    }
}
```

### Factory contracts (multi-implementation)

For interfaces with multiple valid implementations at runtime:

```php
#[InjectAsFactory]
protected NotifierFactoryInterface $notifiers;

// Usage:
$emailNotifier = $this->notifiers->get('email');
$smsNotifier   = $this->notifiers->get('sms');
$default       = $this->notifiers->getDefault();
$keys          = $this->notifiers->keys(); // ['email', 'sms']
```

### Rules

- **Do** use `#[SatisfiesServiceContract]` for single-implementation contracts.
- **Do** use `#[SatisfiesRepositoryContract]` for repository interfaces.
- **Do** use `registry:sync:contracts` for maintenance/debug flows around generated resolvers.
- **Don't** wire contracts manually — let the registry handle resolution.

The point is not "DI flexibility." The point is making substitution readable, reviewable, and deterministic.

---

## 10. Configuration

### Readonly config objects

```php
readonly class MyFeatureConfig
{
    public function __construct(
        public bool   $enabled       = false,
        public string $apiKey        = '',
        public int    $maxRetries    = 3,
        public array  $allowedHosts  = [],
    ) {}

    public static function fromEnvironment(): self
    {
        return new self(
            enabled:      Environment::getEnvValue('MY_FEATURE_ENABLED') === 'true',
            apiKey:       Environment::getEnvValue('MY_FEATURE_API_KEY', ''),
            maxRetries:   (int) Environment::getEnvValue('MY_FEATURE_MAX_RETRIES', '3'),
            allowedHosts: array_filter(explode(',', Environment::getEnvValue('MY_FEATURE_ALLOWED_HOSTS', ''))),
        );
    }
}
```

### Environment precedence order

1. Process env (`getenv()`) — Docker / OS
2. `.env` — local overrides (gitignored)
3. `.env.default` — committed project defaults

System env variables are **never** overwritten by `.env` files.

### Rules

- **Do** use `readonly class` for config objects.
- **Do** provide a `fromEnvironment()` / `fromEnv()` static factory.
- **Do** always provide sensible defaults in the constructor.
- **Do** prefix env vars with a module/feature namespace: `LOCALE_*`, `TENANCY_*`, `MY_FEATURE_*`.
- **Don't** read env vars directly in handlers — inject a config object.
- **Don't** use XML, YAML, or INI for configuration — env vars + PHP attributes only.

---

## 11. Validation

### Built-in validation traits

```php
use NotBlankValidationTrait;   // validateNotBlank(field, value, &errors)
use EmailValidationTrait;      // validateEmail(field, value, &errors)
use LengthValidationTrait;     // validateLength(field, value, min, max, &errors)
```

### Validation in payloads

```php
#[AsPayload(path: '/api/users', methods: ['POST'], responseWith: GenericResponse::class)]
class CreateUserPayload implements ValidatablePayload
{
    use NotBlankValidationTrait;
    use EmailValidationTrait;
    use LengthValidationTrait;

    protected string $email = '';
    protected string $password = '';

    public function setEmail(string $email): void { $this->email = $email; }
    public function setPassword(string $password): void { $this->password = $password; }

    public function validate(): PayloadValidationResult
    {
        $errors = [];
        $this->validateNotBlank('email', $this->email, $errors);
        $this->validateEmail('email', $this->email, $errors);
        $this->validateNotBlank('password', $this->password, $errors);
        $this->validateLength('password', $this->password, 8, null, $errors);
        return new PayloadValidationResult(empty($errors), $errors);
    }
}
```

Validation runs after hydration and before the handler executes.

On failure, the framework returns HTTP 422:
```json
{"errors": {"email": ["Must not be blank."]}}
```

### Type enforcement

`RequestDtoHydrator` casts values to setter parameter types. In strict mode (testing only), `TypeMismatchException` is thrown for mismatches → 422.

### Rules

- **Do** implement `ValidatablePayload` on any payload accepting user input.
- **Do** compose validation via traits — don't duplicate validation logic.
- **Do** return structured errors keyed by field name.
- **Don't** validate in handlers — validate in the payload itself.
- **Don't** rely on type casting for validation — explicitly check business rules in `validate()`.

---

## 12. Routing & URL Generation

### Route naming

Routes are automatically named after the payload class short name. Override with `name:`:

```php
#[AsPayload(path: '/api/login', methods: ['POST'], name: 'auth.login', ...)]
```

### Route lookup

In application code, prefer route names plus URL generation over coupling to discovery internals.

### URL generation (SSR)

```php
// In handlers or services:
\Semitexa\Ssr\Routing\UrlGenerator::to('auth.login');                      // '/api/login'
\Semitexa\Ssr\Routing\UrlGenerator::to('user.profile', ['id' => $userId]); // '/users/{id}' -> '/users/abc-123'

// In Twig:
{{ url('auth.login') }}
{{ url('user.profile', {id: user.id}) }}
{{ current_url({page: 2}) }}
```

### Special route: custom 404 page

```php
#[AsPayload(path: '/404', methods: ['GET'], name: 'error.404', responseWith: Error404Resource::class)]
class Error404Payload {}
```

The framework automatically invokes this when no route matches.

### Rules

- **Do** use meaningful route names: `module.action` (e.g., `user.login`, `orders.create`).
- **Do** use `requirements:` to constrain path parameters.
- **Do** use `UrlGenerator::to()` for generating URLs — never hardcode paths.
- **Don't** couple application code to internal route-discovery APIs when a route name is enough.
- **Don't** register the same path + method combination in multiple payloads without `overrides:`.

---

## 13. Templates & SSR

Semitexa SSR should feel composed, not improvised.

Templates are not where architecture gets rescued. By the time rendering begins, payloads, resources, and slot boundaries should already be clear.

That is why Semitexa pushes so hard on typed resources and explicit render handles.

### Template namespacing

Templates are referenced as `@project-layouts-{ModuleName}/path/to/template.html.twig`.

Declare the template on the Resource class via `#[AsResource(template: '...')]` — the framework renders it automatically. When a Resource serves multiple templates, call `renderTemplate()` explicitly from the handler. Page resources mutate their own accumulated context through typed `with*()` methods; slot resources follow the same pattern, but their internal `with()` helper returns a cloned instance.

```php
// Declared on resource (auto-rendered — handler doesn't call renderTemplate):
#[AsResource(handle: 'homepage', template: '@project-layouts-SsrDemo/pages/homepage.html.twig')]
class HomepageResource extends HtmlResponse implements ResourceInterface { ... }

// Explicit call (shared resource, template chosen per handler):
$response->renderTemplate('@project-layouts-SsrDemo/pages/components.html.twig');
```

### Components

```php
#[AsComponent(name: 'Alert', template: '@project-layouts-SsrDemo/components/Alert.html.twig')]
class AlertComponent {}
```

```twig
{{ component('Alert', {type: 'warning', message: 'Hello'}) }}
```

### Slot resources

```php
#[AsSlotResource(
    handle: 'demo-page',
    slot: 'sidebar_left',
    template: '@project-layouts-SsrDemo/partials/sidebar-left.html.twig',
    priority: 10,
    clientModules: ['@project-static-SsrDemo/slots/sidebar-left.js'],
)]
final class DemoPageSidebarLeftSlot extends HtmlSlotResponse
{
    public function withItems(array $items): static
    {
        return $this->with('items', $items);
    }
}
```

```twig
{{ layout_slot('sidebar_left') }}
```

### Slot handlers

```php
#[AsSlotHandler(slot: DemoPageSidebarLeftSlot::class, priority: 10)]
final class DemoPageSidebarLeftSlotHandler implements TypedSlotHandlerInterface
{
    public function handle(object $slot): object
    {
        if (!$slot instanceof DemoPageSidebarLeftSlot) {
            return $slot;
        }

        return $slot->withItems([
            ['label' => 'Docs', 'url' => '/docs'],
            ['label' => 'Blog', 'url' => '/blog'],
        ]);
    }
}
```

Today the slot handler contract is intentionally broad (`handle(object $slot): object`). In practice, keep each handler dedicated to one slot class, guard the incoming object once, and return the same slot subtype. Use slot resources for renderable page regions and slot handlers for their data hydration. This applies to sync, deferred, and live slot rendering alike.

### Built-in Twig functions

| Function | Description |
|---|---|
| `url(route, params)` | Generate URL from route name |
| `current_url(overrides)` | Current URL with overridden query params |
| `trans(key, params)` | Translation lookup |
| `trans_choice(key, count, params)` | Plural-aware translation |
| `locale()` | Current locale code |
| `component(name, props, slots)` | Render a component |
| `layout_slot(slot, extraContext)` | Render a layout slot |
| `page_title(?title)` | Get/set SEO title |
| `asset(path, ?module)` | Asset URL |
| `semantic_head()` | JSON-LD output plus route-derived alternate links for declared non-HTML formats |

### SEO

Set SEO metadata via the Resource DTO — never call `SeoMeta` directly in handlers:

```php
// In handler:
$resource->pageTitle('My Page');                        // sets browser tab title
$resource->seoTag('description', 'Page description');   // sets <meta name="description">
$resource->seoTag('og:title', 'My Page');               // sets Open Graph tag
```

`pageTitle()` and `seoTag()` wrap `SeoMeta::setTitle()` / `SeoMeta::tag()` and return `$this` for fluent chaining. In Twig, use the public helpers such as `{{ page_title() }}` and `{{ semantic_head() }}` rather than wiring SEO state manually inside handlers.

`semantic_head()` is also where SSR emits route-derived `<link rel="alternate" ...>` tags. Those alternates come from the current payload DTO's declared `produces` formats, not from template-local guesses.

### Template directory convention

All modules must follow the canonical `templates/` subdirectory layout:

| Directory | Purpose |
|-----------|---------|
| `templates/pages/` | Page-level templates (one per route) |
| `templates/layouts/` | Layout templates (extend chains) |
| `templates/partials/` | Reusable fragments (included by multiple pages) |
| `templates/components/` | Component-specific templates |
| `templates/deferred/` | Templates for deferred/async slots |

Not all subdirectories need to exist — only create what the module uses. The `lint:templates` CLI command validates structure across all modules (runs in CI as a blocking check).

### Theme resolution

Themes are **manifest-driven**, not env-driven. The legacy `THEME` environment variable is no longer how themes are selected.

**Theme = manifest + sparse override surface.** A theme is anything declared by a `theme.json`:

```text
packages/<vendor>-<pkg>/theme.json     # packaged theme (e.g. semitexa-theme ships theme-base)
src/theme/<theme-id>/theme.json        # project-local theme
```

A `theme.json` declares:

```json
{
    "id": "marketing-site",
    "extends": "theme-base",
    "active_when": { "domain": "marketing.example.test" },
    "skin": { "default": "marketing-coral" }
}
```

| Field | Purpose |
|---|---|
| `id` | Unique theme identity. Must not collide with `theme-base` (the framework root). |
| `extends` | Parent theme — drives template fallback chain. |
| `active_when` | Per-request gate: `{ "always": true }` (root only — `theme-base` claims this), `{ "domain": "..." }`, or other axes. Exactly one manifest may be `always`. |
| `skin` | Skin selection — `{ "default": "<slug>" }` or conditional. The skin slug must exist (see §13 Skins). |

**Selection at request time.** `ApplyThemeOnAuthCheckListener` walks the discovered theme set and picks the first whose `active_when` matches the current request (host, path, etc.). The chosen theme + its `extends` chain becomes the fallback path for template resolution. There is no global env switch.

**Template override paths.** Inside the theme directory, override sub-paths mirror the parent module's tree:

```text
src/theme/marketing-site/
  theme.json
  theme-base/                        # overrides into theme-base templates/static
    templates/layouts/base.html.twig
    Static/css/marketing-overrides.css
  Hello/                             # overrides into the Hello module
    templates/pages/index.html.twig
```

Sparse: create only files that differ from the parent. Twig's namespace fallback walks the extends-chain automatically.

**Useful commands:**

| Command | What it does |
|---|---|
| `bin/semitexa theme:scaffold --slug=<id>` | Creates `src/theme/<id>/` with a starter `theme.json` extending `theme-base`. |
| `bin/semitexa theme:validate` | CI-safe validation across every `theme.json` (parses, ids unique, extends targets exist, no cycles, exactly one `always` root, every skin slug resolves). |
| `bin/semitexa theme:resolve` | Diagnose which theme matches a given request context. |

**Rules:**

- **Do** create new themes via `theme:scaffold` so the manifest is well-formed.
- **Do** gate non-root themes with a real `active_when` axis (domain/path/etc.) — only `theme-base` may be `always: true`.
- **Do** keep theme directories sparse — every override is a maintenance cost.
- **Don't** read or set a `THEME` env var to pick a theme — it is not how selection works anymore.
- **Don't** put runtime business logic in a theme; themes are presentation overrides only.

### 13.1 Skins

A **skin** is the visual token set referenced by a theme — colors, surfaces, borders, radii, shadows, motion, charts. Themes pick which skin is active; modules consume the resulting CSS variables.

**Where skins live:**

| Source | Path | Discovery |
|---|---|---|
| Framework default | `vendor/semitexa/theme/src/Application/Static/skins/<slug>/` | `SkinDiscovery::FRAMEWORK_SKINS_DIR` |
| Project-local | `src/skins/<slug>/` | `SkinDiscovery::PROJECT_SKINS_DIR` (project wins on slug collision) |

Each skin directory contains exactly two files:

```text
src/skins/<slug>/
  skin.json             # source of truth (algorithm, seed, knobs, history, light+dark token map)
  tokens.css            # generated artifact (canonical structure: 7 sections, light-dark() per token)
```

Both sources are served at the unified URL `/assets/skins/<slug>/tokens.css`.

**`skin.json` (schema 3.0) — what it declares:**

```json
{
    "name": "marketing-coral",
    "schema_version": "3.0",
    "source": "seed",
    "algorithm": "balanced",          // "balanced" | "glass" | "brutalist"
    "seed": "#c96d49",
    "knobs": { "radius_scale": "default", "shadow_intensity": "default", "motion_speed": "default" },
    "generated_at": "...",
    "updated_at": "...",
    "history": [ { "at": "...", "kind": "generate", "algorithm": "balanced", "seed": "...", "knobs": {…} } ],
    "tokens": {
        "light": { "--ui-surface-page": "#…", "--ui-accent-brand": "#…", "…": "…" },
        "dark":  { "--ui-surface-page": "#…", "--ui-accent-brand": "#…", "…": "…" }
    }
}
```

**`tokens.css` is generated from `skin.json` — never hand-edited.** Layout is canonical: a 5-line header block, `:root { color-scheme: light dark; ... }` with seven labeled sections in fixed order — `COLOR · STATE · CHART · FORM · DEPTH · MOTION · EFFECT` — every variant token wrapped in `light-dark(L, D)`, mode-invariant tokens emitted as a single value, plus `:root[data-skin-mode="light|dark"]{ color-scheme: ...; }` mode-pinning blocks. `bin/semitexa lint:skin-tokens` is a regression guard that re-emits `tokens.css` from `skin.json` and fails on drift.

**Public token surface** is the `--ui-*` set declared by `TokenContract` (currently 41 tokens across 7 sections). Module CSS consumes only those names. Algorithm internals and primitives are intentionally **not** part of the surface — they would freeze algorithm choices if exposed.

**Workflow (canonical, project-local):**

1. **Edit the source.** Modify `src/skins/<slug>/skin.json` (seed, knobs, or explicit token overrides).
2. **Re-emit the artifact.** Run `bin/semitexa skins:rebuild <slug>` to regenerate `tokens.css` deterministically from `skin.json`.
3. **Lint.** `bin/semitexa lint:skin-tokens` verifies no drift between source and artifact across every skin.
4. **Reference from a theme.** Add `"skin": { "default": "<slug>" }` in the theme's `theme.json`.

> **Caveat:** the `skins:generate` / `skins:refine` / `skins:rebuild` commands are produced by a skin-generation toolkit that ships in a separate package. The framework-side classes (`TokenEmitter`, `SkinBuilder`, `SkinManifest`, `SkinAlgorithmRegistry`) are present in `packages/semitexa-theme/src/Skin/` and reference these commands in operator-facing messages. If a fresh checkout does not include the toolkit, the corresponding commands may be unavailable — verify with `bin/semitexa list` or `bin/semitexa help` before depending on them. The lint guard (`bin/semitexa lint:skin-tokens`) is always available.

**What gets committed vs. what is generated:**

| File | Status | Edit by hand? |
|---|---|---|
| `skin.json` | Source of truth — committed | Yes — this is the authored input |
| `tokens.css` | Generated artifact — committed | **No — regenerate via `skins:rebuild`** |

Committing both lets the lint guard catch drift on PRs. Treat `tokens.css` like a code-generated file: delete it locally if needed, but never edit it by hand.

**Rules:**

- **Do** edit `skin.json`, re-emit `tokens.css` via `skins:rebuild`, and let `lint:skin-tokens` guard the result.
- **Do** consume only `--ui-*` tokens from module CSS. The algorithm-internal variables are not part of the public contract.
- **Do** put a project-local skin under `src/skins/<slug>/` — it overrides any framework-default of the same slug.
- **Don't** hand-edit `tokens.css`. The lint guard will fail; fix the source instead.
- **Don't** introduce new tokens to `--ui-*` casually — that surface is a contract; expand it deliberately, not per skin.
- **Don't** confuse skin with theme: theme = template/asset override + selection; skin = visual token set referenced by the theme.

### Static assets

**Modules and packages:** Static assets live in `Application/Static/css/` and `Application/Static/js/` and are discovered at worker boot via a **required** `Application/Static/assets.json` manifest. The manifest uses include rules, so developers do not list every file.

**Naming convention:** `{ModuleName}:{type}:{relative-path-without-extension}`

| File | Logical Name |
|------|-------------|
| `Static/css/demo.css` | `SsrDemo:css:demo` |
| `Static/css/components/card.css` | `SsrDemo:css:components/card` |
| `Static/js/sse-client.js` | `SsrDemo:js:sse-client` |

**Defaults by convention:**

| Location | Position | Default scope | Default priority |
|----------|----------|---------------|-----------------|
| `Static/css/**/*.css` | `head` | `module` | `50` |
| `Static/js/**/*.js` | `body` | `page` | `90` |

The more the rendering layer follows convention, the less every team has to reinvent its own front-end wiring model.

**`Application/Static/assets.json` (v2, required):**

```json
{
    "$schema": "semitexa://asset-manifest/v2",
    "module": "SsrDemo",
    "include": {
        "css": ["css/**/*.css"],
        "js": ["js/**/*.js"]
    },
    "overrides": {
        "css/theme-dark.css": { "scope": "page", "priority": 10 }
    },
    "exclude": ["js/dev-only.js"]
}
```

**Theme asset overrides:** Place files at `src/theme/{theme-id}/{parent-or-module}/Static/` with the same relative path. The theme version wins; the logical name and URL remain unchanged. Theme selection is per-request and manifest-driven (see §13.1 Theme resolution) — there is no `THEME` env var.

**Packages:** Follow the same `Application/Static/` + v2 manifest rules as app modules (One Way).

**Load ordering:** priority (ascending) → module topological order → lexicographic file path.

### Rules

- **Do** use `@project-layouts-{ModuleName}/` namespace for all template references.
- **Do** declare the page template in `#[AsResource(template: '...')]` — let auto-render handle it.
- **Do** use components for reusable UI pieces and slot resources for page regions.
- **Do** declare slot blocks in `Application/Resource/Slot/` via `#[AsSlotResource]`.
- **Do** hydrate slot blocks via `#[AsSlotHandler]`.
- **Do** declare slot-owned browser logic via `clientModules` on the slot resource.
- **Do** set SEO metadata via `$resource->pageTitle()` / `$resource->seoTag()` in handlers — never call `SeoMeta::setTitle()` or `SeoMeta::tag()` directly.
- **Do** use the canonical template subdirectories (`pages/`, `layouts/`, `partials/`, `components/`, `deferred/`).
- **Do** place new CSS/JS files in `Application/Static/` and ensure the default include rules (`css/**/*.css`, `js/**/*.js`) cover them.
- **Do** use theme overrides (`src/theme/{theme-id}/`) for theme-specific templates and assets, scaffolded via `bin/semitexa theme:scaffold`.
- **Do** let page resources auto-render by default; reach for `disableAutoRender()` only in genuinely custom response flows.
- **Don't** put business logic in Twig templates.
- **Don't** pass a context array to `renderTemplate()` — pre-populate via `with*()` methods on the Resource.
- **Don't** put inline JavaScript snippets into slot metadata — use `clientModules` asset references.
- **Don't** use provider classes as the canonical slot hydration model.
- **Don't** list every asset file in `assets.json`; use include rules and optional overrides/exclude.
- **Don't** use non-canonical template subdirectory names (e.g., `blocks/` instead of `components/`).

---

## 14. Tenancy

### Coroutine-safe context

```php
// Set once per request (immutable after that):
CoroutineContextStore::set($tenantContext);

// Read anywhere in the same coroutine:
$ctx = CoroutineContextStore::get();
$tenantId = $ctx->getTenantId();
$locale   = $ctx->getLayer(new LocaleLayer())?->rawValue();
```

### Tenant layers

```php
#[AsTenancyLayersProvider]
class TenantLayersProvider
{
    public function layers(): array
    {
        return [
            new LayerDefinition(
                layer: new OrganizationLayer(),
                strategy: new OrganizationStrategy(
                    new SubdomainStrategy(baseDomain: $this->getBaseDomain())
                ),
            ),
            new LayerDefinition(
                layer: new LocaleLayer(),
                strategy: new LocaleStrategy(
                    new PathStrategy(prefixes: ['en', 'uk', 'de'])
                ),
            ),
        ];
    }
}
```

### Tenant isolation guard

```php
#[TenantIsolated]
#[AsPayloadHandler(payload: MyPayload::class, resource: GenericResponse::class)]
final class MyHandler implements TypedHandlerInterface { ... }
```

Returns 403 if no tenant context is resolved.

### Rules

- **Do** use `CoroutineContextStore` for tenant context — it's coroutine-safe.
- **Do** use `#[TenantIsolated]` on handlers that require a tenant.
- **Don't** mutate tenant context after it's set — it's immutable per request.
- **Don't** use process-global statics for tenant state in Swoole mode.

---

## 15. Locale & i18n

### Translation service

```php
$service->trans('welcome');                        // "Welcome to the app"
$service->trans('hello', ['name' => 'World']);      // "Hello, World!"
$service->transChoice('items', 5);                 // "5 items"
$service->trans('welcome', locale: 'uk');           // Explicit locale override
```

### Twig

```twig
{{ trans('nav.home') }}
{{ trans('greeting', {name: user.name}) }}
{{ trans_choice('items', count) }}
{{ locale() }}
```

### Module-scoped translations

```php
$service->trans('MyModule.welcome'); // Scoped to MyModule's message catalog
```

### Coroutine-safe locale storage

```php
LocaleContextStore::setLocale('uk');
LocaleContextStore::getLocale();       // 'uk'
LocaleContextStore::setFallbackLocale('en');
```

### Rules

- **Do** store translations as JSON files per locale.
- **Do** use `trans()` / `trans_choice()` — never hardcode user-facing strings.
- **Do** scope translation keys with module prefix when ambiguity is possible.
- **Don't** access `LocaleContextStore` from worker-scoped (readonly) services — locale is per-request.

---

## 16. Queue & Async Handlers

### Declaring an async handler

```php
#[AsPayloadHandler(
    payload: OrderPlacedPayload::class,
    resource: GenericResponse::class,
    execution: HandlerExecution::Async,
    transport: 'nats',
    queue: 'orders',
    maxRetries: 3,
    retryDelay: 5,
)]
final class SendOrderConfirmationHandler implements TypedHandlerInterface
{
    public function handle(OrderPlacedPayload $payload, GenericResponse $resource): GenericResponse
    {
        // This runs in a queue worker, not during the HTTP request
        // ...
        return $resource;
    }
}
```

### Execution flow

1. `PipelineExecutor` encounters an async handler in the handler group.
2. Serializes payload + resource DTOs into a `QueuedHandlerMessage`.
3. Publishes to the configured transport/queue.
4. `QueueWorker` picks it up, deserializes, instantiates the handler, calls `handle()`.

### Retry & DLQ

- On failure, re-enqueues up to `maxRetries` times with `retryDelay` seconds between.
- After exhausting retries, moves to dead-letter queue: `"{queueName}.failed"`.

### Transports

| Env Config | Transport |
|---|---|
| `EVENTS_TRANSPORT=nats` | Force NATS transport |
| `EVENTS_TRANSPORT=in-memory` | Force in-memory transport |
| `EVENTS_ASYNC=1` | NATS by default unless `EVENTS_TRANSPORT` overrides it |
| Default | In-memory transport |

### Rules

- **Do** use `HandlerExecution::Async` for side-effect operations (email, analytics, file processing).
- **Do** set `maxRetries` and `retryDelay` for async handlers.
- **Do** keep async handler logic idempotent — retries can cause duplicate execution.
- **Don't** depend on async handler results in the HTTP response — the handler is queued instead of running inline.
- **Don't** access `Session` or `CookieJar` in async handlers — they're not available.

---

## 17. Testing

### Running tests — always through `bin/semitexa test:run`

The only blessed entry point is the containerized command:

```bash
bin/semitexa test:run                                     # full suite
bin/semitexa test:run tests/Unit/SomeTest.php             # narrowed path
bin/semitexa test:run -- --filter someTestName            # extra phpunit args after one '--'
bin/semitexa test:run -- --testsuite Unit                 # phpunit testsuite filter
```

Why this matters:

- the container guarantees the right PHP / Swoole / extension surface;
- it mirrors how CI runs;
- direct host-side `vendor/bin/phpunit` invocations silently produce different results and are not supported.

**Argument passing gotcha:** `bin/semitexa test:run` accepts a single trailing `--` to forward the rest as PHPUnit arguments. Never use a leading `--` separator before phpunit arguments.

```bash
# CORRECT — first non-option is the path, then '--' once before phpunit flags
bin/semitexa test:run tests/Unit/X.php -- --filter foo

# WRONG — leading '--' before phpunit args breaks parsing
bin/semitexa test:run -- tests/Unit/X.php --filter foo
```

### `#[TestablePayload]` attribute

```php
#[AsPayload(path: '/api/login', methods: ['POST'], responseWith: GenericResponse::class)]
#[TestablePayload(
    strategies: [ParanoidProfileStrategy::class, LoginEmailFormatStrategy::class],
)]
class LoginPayload implements ValidatablePayload { ... }
```

### Strategy profiles

| Profile | Includes |
|---|---|
| `StandardProfileStrategy` | `SecurityStrategy`, `HttpMethodStrategy` |
| `StrictProfileStrategy` | + `TypeEnforcementStrategy` |
| `ParanoidProfileStrategy` | + `MonkeyTestingStrategy` |

### Running contract tests

```php
class LoginPayloadContractTest extends TestCase
{
    use TestsPayloads;

    public function test_login_payload_contract(): void
    {
        $this->assertPayloadContract(LoginPayload::class);
    }
}
```

### Custom test strategy

```php
final class LoginEmailFormatStrategy implements TestingStrategyInterface
{
    public function canRun(PayloadMetadata $metadata): bool { return true; }
    public function skipReason(PayloadMetadata $metadata): string { return ''; }

    public function generateCases(PayloadMetadata $metadata): iterable
    {
        yield new TestCaseDescriptor(
            description: 'Malformed email returns 422',
            method: 'POST',
            path: $metadata->path,
            headers: [],
            body: ['email' => 'not-an-email', 'password' => 'secret'],
            expectedStatus: 422,
        );
    }

    public function assertResponse(TestCaseDescriptor $case, ResponseResult $response): void
    {
        Assert::assertSame($case->expectedStatus, $response->statusCode, $case->description);
    }
}
```

### Transports

| Transport | Use |
|---|---|
| `InProcessTransport` | Default. Calls `Application::handleRequest()` in-process. Fast. |
| `HttpTransport` | Makes real HTTP requests. Use for full integration tests. |

### Rules

- **Do** run all tests via `bin/semitexa test:run` — never `vendor/bin/phpunit` from the host.
- **Do** add `#[TestablePayload]` to every payload.
- **Do** start with `StandardProfileStrategy` and escalate to `ParanoidProfileStrategy` for sensitive endpoints.
- **Do** write custom strategies for domain-specific validation rules.
- **Don't** skip security strategy on public endpoints — it tests for injection, XSS, header attacks.
- **Don't** invoke `vendor/bin/phpunit` directly — it bypasses the container and may produce false positives or false negatives.

---

## 18. Security

### Auth guards on payloads

The authorization model is default-deny: every payload requires authentication unless explicitly marked `#[PublicEndpoint]`.

```php
// Protected by default — no attribute needed.
#[AsPayload(path: '/api/profile', methods: ['GET'], responseWith: GenericResponse::class)]
class ProfilePayload {}

// Explicitly public — anonymous access allowed.
#[PublicEndpoint]
#[AsPayload(path: '/api/login', methods: ['POST'], responseWith: GenericResponse::class)]
class LoginPayload {}

// Protected with fine-grained permission check.
#[RequiresPermission('users.manage')]
#[AsPayload(path: '/api/admin/users', methods: ['GET'], responseWith: GenericResponse::class)]
class AdminUsersPayload {}
```

All three attributes (`#[PublicEndpoint]`, `#[RequiresCapability]`, `#[RequiresPermission]`) are from `Semitexa\Authorization\Attributes`.

Capability vs permission:

- `#[RequiresCapability]` is a coarse-grained gate for broad access such as `admin`, `staff`, or `backoffice`.
- `#[RequiresPermission]` is a fine-grained RBAC check for concrete actions such as `users.manage` or `orders.refund`.

Example:

```php
#[RequiresCapability('admin')]
#[AsPayload(path: '/api/admin/dashboard', methods: ['GET'], responseWith: GenericResponse::class)]
class AdminDashboardPayload {}
```

### Pipeline execution order

```text
Built-in pipeline phases:
  1. AuthCheck
  1. AuthBootstrapper runs auth handlers (session, token, etc.)
     — in BestEffort mode for public endpoints, Mandatory mode for protected.
  2. AuthorizationListener resolves access policy from payload attributes.
  3. Policy evaluation: public → allow; guest on protected → 401;
     missing capability → 403; missing permission → 403.
  2. HandleRequest
  1. The matched payload handler executes after AuthCheck passes.

All exceptions are caught by ExceptionMapper → content-negotiated error response.
```

### Session security

- Session ID: 32 hex characters, regenerated on login.
- Cookie: `HttpOnly; SameSite=lax; Secure` (in production).
- Backend: Redis (`REDIS_HOST`) or Swoole Table (dev).

### Rules

- **Do** mark every explicitly public endpoint with `#[PublicEndpoint]` — all others are protected by default.
- **Do** use `#[RequiresPermission]` for fine-grained RBAC.
- **Do** use `#[RequiresCapability]` for coarse-grained endpoint access gating.
- **Do** call `$session->regenerate()` after authentication state changes.
- **Do** validate and sanitize all input in the payload's `validate()` method.
- **Don't** trust client-provided IDs without authorization checks.
- **Don't** store secrets in payloads or resources.
- **Don't** disable `HttpOnly` or `SameSite` on session cookies.

---

## 19. Swoole & Coroutine Safety

### Golden rule

> In Swoole HTTP mode, **all per-request mutable state** must use `Coroutine::getContext()` or `RequestScopedContainer`. Never use process-global statics for request-scoped data.

### Safe statics (immutable after worker start)

| Class | Why static is safe |
|---|---|
| `AttributeDiscovery` | Routes are immutable after initialization |
| `ClassDiscovery` | Classmap is immutable after initialization |
| `PipelineListenerRegistry` | Listener list is immutable after initialization |
| `HandlerReflectionCache` | Write-once at boot, read-only at runtime — closures are reentrant |
| `QueueConfig` | Reads env vars only |

### Per-request state (must be coroutine-isolated)

| Store | Mechanism |
|---|---|
| `LocaleContextStore` | `Coroutine::getContext()` with static fallback for CLI |
| `CoroutineContextStore` (tenancy) | `Coroutine::getContext()` with lock-after-set |
| `RequestScopedContainer` | Reset per request in `Application` |
| `SeoMeta` | Coroutine-safe per-request storage |

### Pattern for coroutine-safe storage

```php
final class MyContextStore
{
    private const KEY = '__my_context';
    private static string $staticFallback = '';

    public static function set(string $value): void
    {
        if (self::inCoroutine()) {
            Coroutine::getContext()[self::KEY] = $value;
            return;
        }
        self::$staticFallback = $value;
    }

    public static function get(): string
    {
        if (self::inCoroutine()) {
            return Coroutine::getContext()[self::KEY] ?? self::$staticFallback;
        }
        return self::$staticFallback;
    }

    private static function inCoroutine(): bool
    {
        return class_exists(Coroutine::class, false) && Coroutine::getCid() > 0;
    }
}
```

### Rules

- **Do** always provide a static fallback for CLI/test mode.
- **Do** use `Coroutine::getContext()` for per-request isolation.
- **Don't** store request data in class properties of `#[InjectAsReadonly]` services.
- **Don't** use `static $var` for request-scoped data in services.
- **Don't** use global `$_SESSION`, `$_COOKIE`, `$_GET`, `$_POST` — they're shared across all coroutines.

---

## 20. Error Handling

### DomainException hierarchy

All domain errors extend `Semitexa\Core\Exception\DomainException` and map to HTTP status codes automatically via `ExceptionMapper`:

```text
DomainException (abstract, extends \RuntimeException)
  ├─ NotFoundException              → 404  (error code: "not_found")
  ├─ ValidationException            → 422  (error code: "validation_failed", carries field errors)
  ├─ AuthenticationException        → 401  (error code: "authentication_failed")
  ├─ AccessDeniedException          → 403  (error code: "access_denied")
  ├─ ConflictException              → 409  (error code: "conflict")
  └─ RateLimitException             → 429  (error code: "rate_limited", carries retry_after)

Other exceptions:
  ├─ TenantRequiredException        → 403
  ├─ TenantContextImmutableException
  └─ TypeMismatchException          → 422
```

Legacy aliases (deprecated, still work):
- `Pipeline\Exception\AuthenticationRequiredException` → extends `AuthenticationException`
- `Pipeline\Exception\AccessDeniedException` → extends `AccessDeniedException`
- `Http\Exception\NotFoundException` → extends `DomainException`

### HttpStatus enum

Use `Semitexa\Core\Http\HttpStatus` instead of magic integers:

```php
HttpStatus::Ok                  // 200
HttpStatus::Created             // 201
HttpStatus::NotFound            // 404
HttpStatus::UnprocessableEntity // 422

$status->reason();       // "Not Found"
$status->isClientError(); // true
$status->value;          // 404
```

### ExceptionMapper — automatic error responses

`RouteExecutor` wraps the pipeline in try/catch. `ExceptionMapper` converts thrown exceptions into content-negotiated error responses:

```php
// Handler throws:
throw new NotFoundException('User', $id);

// ExceptionMapper produces (JSON):
// HTTP 404
// {"error": "not_found", "message": "User #42 not found."}

// Handler throws:
throw new ValidationException(['email' => ['Email is required']]);

// ExceptionMapper produces (JSON):
// HTTP 422
// {"error": "validation_failed", "message": "Validation failed.", "errors": {"email": ["Email is required"]}}
```

The error format is the same for JSON, XML, and HTML (error page template).

### In handlers

```php
// Errors — throw domain exceptions (ExceptionMapper handles the response)
throw new NotFoundException('User', $payload->id);
throw new ValidationException(['email' => ['Email already exists']]);
throw new AccessDeniedException('Insufficient permissions.');
throw new ConflictException('Resource already exists.');
throw new RateLimitException(retryAfter: 60);

// Never return Response objects from TypedHandlerInterface handlers.
// Never throw generic \Exception — use typed DomainException subclasses.
```

### Custom domain exceptions

```php
class InsufficientBalanceException extends DomainException
{
    public function __construct(float $balance, float $required)
    {
        $this->balance = $balance;
        $this->required = $required;
        parent::__construct("Insufficient balance: {$balance}, required: {$required}");
    }

    private float $balance;
    private float $required;

    public function getStatusCode(): HttpStatus { return HttpStatus::UnprocessableEntity; }

    public function getErrorContext(): array
    {
        return ['balance' => $this->balance, 'required' => $this->required];
    }
}
```

### Response conventions

| Status | Exception | When |
|---|---|---|
| 200 | — | Success |
| 201 | — | Resource created (`$resource->setStatusCode(201)`) |
| 301/302 | — | Redirect (`$resource->setRedirect(...)`) |
| 401 | `AuthenticationException` | Not authenticated |
| 403 | `AccessDeniedException` | Not authorized |
| 404 | `NotFoundException` | Entity not found |
| 409 | `ConflictException` | Duplicate resource, stale update |
| 415 | — | Unsupported Content-Type (content negotiation) |
| 422 | `ValidationException` | Validation failure |
| 429 | `RateLimitException` | Throttle exceeded |
| 500 | any `\Throwable` | Unexpected error (details logged, not exposed) |

### Rules

- **Do** throw `DomainException` subclasses from handlers — the framework owns the response.
- **Do** use the `HttpStatus` enum instead of magic integers.
- **Do** provide `getErrorContext()` for structured error data (field errors, retry info).
- **Do** create custom `DomainException` subclasses for domain-specific errors.
- **Don't** return `Response` objects from handlers — throw exceptions for errors, use resource methods for success.
- **Don't** swallow exceptions silently — log them or let `ExceptionMapper` handle them.
- **Don't** expose stack traces or internal details in production error responses (ExceptionMapper sanitizes unknown exceptions automatically).

---

## 21. PHP Code Style

### Mandatory conventions

```php
// Every file:
declare(strict_types=1);

// Every handler, listener, event listener:
final class MyHandler { ... }

// Config objects and value-object / internal DTOs:
// never Payload/Resource DTOs (they must stay mutable for hydrators).
readonly class MyConfig { ... }

// Constructor property promotion (always):
public function __construct(
    private readonly SomeInterface $service,
    private readonly ?OtherInterface $optional = null,
) {}

// Named arguments in attributes (always):
#[AsPayload(path: '/api/users', methods: ['POST'], responseWith: GenericResponse::class)]

// match over switch:
$result = match ($strategy) {
    'path'   => new PathResolver(),
    'header' => new HeaderResolver(),
    default  => throw new \InvalidArgumentException("Unknown strategy: {$strategy}"),
};
```

### PHP 8.x features to use

| Feature | Example |
|---|---|
| `readonly class` | Config objects, DTOs, Request, Response |
| `readonly` properties | Attribute parameters, DI constructor args |
| Constructor promotion | All constructors |
| Named arguments | All attribute constructors |
| `match` expressions | Strategy selection, enum mapping |
| Union types | `HandlerExecution\|string\|null` |
| Enums | `HandlerExecution`, `EventExecution`, `HttpStatus` |
| `str_starts_with()` / `str_contains()` | String checks (never `strpos !== false`) |
| First-class callables | `$fn = $this->process(...)` |
| Null-safe operator | `$ctx?->getLayer()?->rawValue()` |

### Rules

- **Do** use `declare(strict_types=1)` in every file.
- **Do** mark all handlers and listeners `final`.
- **Do** use `readonly` for immutable value objects.
- **Do** prefer `match` over `switch`.
- **Don't** use annotations (docblock `@Route`) — use PHP 8 attributes.
- **Don't** use `array_key_exists` when `isset` or `??` suffice.

---

## 22. Anti-Patterns

This section matters as much as the "do" rules.

Most architectural pain does not come from missing features. It comes from small local decisions that look harmless, then slowly dissolve the system shape.

Each anti-pattern below is expensive because it reintroduces ambiguity that Semitexa is trying to remove.

Read this section as a list of ways systems slowly become expensive again.

Most bad architecture does not arrive as a dramatic mistake. It arrives as a series of "just this once" shortcuts that teach the codebase to stop being predictable.

### Don't: Business logic in payloads

```php
// BAD
class OrderPayload {
    public function process(): void { /* calculates total, saves to DB */ }
}

// GOOD — payload is a data carrier, handler has the logic
class OrderPayload { /* just fields + validate() */ }
class OrderHandler implements TypedHandlerInterface { /* processing logic here */ }
```

### Don't: Direct DB access in handlers

```php
// BAD
$pdo = new PDO(...);
$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');

// GOOD — inject repository
#[InjectAsReadonly]
protected UserRepositoryInterface $users;
$user = $this->users->find($id);  // throws NotFoundException if not found
```

### Don't: Global state for request data

```php
// BAD — shared across all concurrent requests in Swoole
static string $currentUser = '';

// GOOD — coroutine-isolated
CoroutineContextStore::set($context);
// or
$this->requestScopedContainer->set('current_user', $user);
```

### Don't: Middleware as handlers

```php
// BAD — auth check in a handler
#[AsPayloadHandler(payload: AdminPayload::class, resource: GenericResponse::class)]
final class AdminAuthHandler implements TypedHandlerInterface {
    public function handle(AdminPayload $payload, GenericResponse $resource): GenericResponse {
        if (!$this->auth->isAdmin()) { throw new AccessDeniedException(); }
        return $resource; // passes through
    }
}

// GOOD — use pipeline listener or authorization attributes
#[RequiresPermission('admin.access')]
#[AsPayload(path: '/api/admin/...', ...)]
class AdminPayload {}
```

### Don't: Return Response objects from handlers

```php
// BAD — bypasses the rendering pipeline, breaks content negotiation
public function handle(UserGetPayload $payload, GenericResponse $resource): GenericResponse {
    return Response::json(['error' => 'Not found'], 404);  // WRONG — LogicException thrown
}

// GOOD — throw domain exceptions, let ExceptionMapper handle it
public function handle(UserGetPayload $payload, GenericResponse $resource): GenericResponse {
    throw new NotFoundException('User', $payload->id);  // → 404 JSON/XML/HTML automatically
}
```

### Don't: instanceof checks in handlers

```php
// BAD (legacy HandlerInterface pattern)
public function handle(PayloadInterface $payload, ResourceInterface $resource): ResourceInterface {
    if (!$payload instanceof UserGetPayload) {
        return Response::json(['error' => 'Invalid payload'], 400);
    }
    // ...
}

// GOOD (TypedHandlerInterface — concrete types enforced by discovery/runtime checks)
public function handle(UserGetPayload $payload, GenericResponse $resource): GenericResponse {
    // No instanceof needed — the pipeline guarantees the types
}
```

### Don't: Hardcoded HTTP status codes

```php
// BAD
return Response::json(['error' => 'Not found'], 404);
$resource->setStatusCode(201);

// GOOD
throw new NotFoundException('User', $id);             // ExceptionMapper → 404
$resource->setStatusCode(HttpStatus::Created->value);  // Self-documenting
```

### Don't: Hardcoded URLs

```php
// BAD
$resource->setRedirect('/api/users/' . $user->id);

// GOOD
$resource->setRedirect(UrlGenerator::to('user.profile', ['id' => $user->id]));
```

### Don't: Tight coupling to Swoole internals

```php
// BAD — breaks in CLI/test mode
$cid = \Swoole\Coroutine::getCid();

// GOOD — use framework abstractions
LocaleContextStore::getLocale();    // handles coroutine vs static automatically
CoroutineContextStore::get();       // handles coroutine vs CLI automatically
```

### Don't: Manual service wiring

```php
// BAD
$handler = new MyHandler(new MyService(new MyRepo($pdo)));

// GOOD — let the container resolve
#[InjectAsReadonly]
protected MyServiceInterface $service;
```

### Don't: Use deprecated HandlerInterface

```php
// BAD — deprecated, will be removed in v2.0
final class MyHandler implements HandlerInterface { ... }

// GOOD
final class MyHandler implements TypedHandlerInterface { ... }
```

> Run `bin/semitexa lint:handlers` to find all remaining legacy handlers. PHPStan also flags `HandlerInterface` usage with the `semitexa.deprecatedHandlerInterface` identifier.

### Don't: Implement PayloadInterface

```php
// BAD — deprecated marker interface, no longer required
class MyPayload implements PayloadInterface {}

// GOOD — plain class, no marker interface needed
class MyPayload {}

// GOOD — with validation (ValidatablePayload is still required)
class MyPayload implements ValidatablePayload { ... }
```

### Don't: Run `vendor/bin/phpunit` directly

```bash
# BAD — bypasses the container, picks up the host PHP/Swoole, may diverge from CI
vendor/bin/phpunit tests/Unit/MyTest.php

# GOOD — runs inside the framework container, matches CI
bin/semitexa test:run tests/Unit/MyTest.php
```

### Don't: Hand-edit `tokens.css`

```css
/* BAD — tokens.css is generated; lint:skin-tokens will fail and your edit will be lost on rebuild */
:root { --ui-accent-brand: #ff0000; }
```

```jsonc
// GOOD — edit src/skins/<slug>/skin.json, then bin/semitexa skins:rebuild <slug>
{ "tokens": { "light": { "--ui-accent-brand": "#ff0000" } } }
```

### Don't: Place console commands or update patches outside the canonical location

```text
# BAD — discovery does not walk these paths
bin/my-script.php
scripts/cleanup.php
src/Console/Reset.php           # only valid inside packages, not under src/

# GOOD — discovered via #[AsCommand]
src/modules/{Module}/Application/Command/{Name}Command.php
packages/semitexa-{pkg}/src/Console/Command/{Name}Command.php

# GOOD — discovered via #[AsDataPatch]
src/modules/{Module}/Application/Update/{PatchName}.php
packages/semitexa-{pkg}/src/Update/{PatchName}.php
```

### Don't: Treat `THEME` as the way to switch themes

```bash
# BAD — has no effect; theme selection is manifest-driven via active_when
THEME=dark bin/semitexa server:start

# GOOD — gate the theme manifest with active_when (domain, path, etc.)
# src/theme/dark/theme.json:
# { "id": "dark", "extends": "theme-base", "active_when": { "domain": "dark.example.test" }, "skin": { "default": "dark" } }
```

### Don't: Treat `var/docs/` as authoritative documentation

```text
# BAD — relying on a long-lived contract document inside scratch
"see var/docs/HANDLER_REFACTORING_TECHNICAL_DESIGN.md for the canonical handler contract"

# GOOD — canonical docs live in packages/semitexa-docs/docs/; var/docs/ is in-flight scratch
"see packages/semitexa-docs/docs/AI_BEST_PRACTICES.md §4 (Handlers)"
```

### Don't: Document planned features as if they exist

```text
# BAD — the doc claims a command/path that no fresh checkout has
"Run `make:patch` to scaffold a data patch."          # no such generator exists today

# GOOD — describe what is in the repo; capture the rest as an ai:epic
"Author the patch class manually under Application/Update/ following §1.2; a make:patch
generator is not part of the framework today (would be a follow-up epic)."
```
