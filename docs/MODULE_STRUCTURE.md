# Semitexa Module Structure — Authoritative Specification

> This document is the **prose mirror** of the executable specification at
> [`packages/semitexa-dev/config/module-structure.php`](../../semitexa-dev/config/module-structure.php).
> The executable spec is what `bin/semitexa ai:verify` actually loads and
> enforces. This document explains the same rules to humans. **When the two
> disagree, the executable spec wins.** Treat any divergence as a defect and
> update both in lockstep.
>
> The validator is **strict allowlist**: every directory and file inside a
> Semitexa module / package must be **explicitly allowed** by the spec.
> Anything that is not declared fails with a `module_structure.*` violation.
> There is no implicit allow, no auto-fallback, no inference from existing
> repository drift.

## Audience

This document is enforced. Undeclared structure is a hard failure of `bin/semitexa ai:verify`.

* **Human readers**: read § 1 – § 5 and the rules table.
* **AI agents**: § 4 (validator contract) is mandatory before placing a file. § 6 explains the failure shape so you can fix and retry.
* **Tooling**: § 7 describes the executable spec so you can edit the rules in lockstep with the doc.

`AI_BEST_PRACTICES.md` defers to this file. If the two ever disagree, this file wins, and the discrepancy is a defect to be reported.

---

## 1. What counts as a "module"

A Semitexa module — for the purposes of this document and the structure validator — is:

| Kind | Path pattern | Validator scope |
|---|---|---|
| Application module | `src/modules/{ModuleName}/` | **In scope** — runtime PHP under `src/`, tests under `tests/`, every rule in § 5 enforced |
| Vendor / package module | `packages/semitexa-{name}/` with `composer.json` | **In scope** — package strategy enforced |

The validator applies the same strict allowlist to both module kinds. Application modules add a local envelope at `src/modules/{Name}/` and then apply the canonical tree (§ 2) under `src/modules/{Name}/src/`. Package modules add a package-envelope check at the filesystem root (composer.json, LICENSE, README, etc. — see the executable spec's `packageRootRule`) and then apply the same strict tree under `packages/semitexa-{name}/src/`. Package source is **not** "intentionally permissive" — exactly the same allowlist applies.

The validator detects a module from a changed file path by walking up the directory tree until it finds either `src/modules/{Name}/` or `packages/semitexa-{name}/` with `composer.json`. If neither applies, the file is ignored by the structure check (other `ai:verify` checks still apply).

---

## 2. Canonical directory tree

```text
{module-root}/
  src/
  Application/
    Payload/
      Request/          # payload DTOs (one of #[AsPublicPayload] / #[AsProtectedPayload] / #[AsServicePayload])
      Event/            # event classes
      Part/             # #[AsPayloadPart] traits
    Resource/
      Response/         # page / HTTP Resource DTOs
      Slot/             # #[AsSlotResource] DTOs
    Handler/
      PayloadHandler/   # #[AsPayloadHandler]
      SlotHandler/      # #[AsSlotHandler]
      DomainListener/   # #[AsEventListener]
    Service/            # application orchestration / adapters
    Console/            # the only allowed child of Application/Console/ is Command/
       Command/         # #[AsCommand] console commands (canonical for both packages and app modules)
    Update/             # #[AsDataPatch] post-schema patches
    Static/             # static assets (manifest-driven, assets.json required)
      css/
      js/
    View/
      locales/
      templates/
        pages/
        layouts/
        partials/
        components/
        deferred/
    Component/          # #[AsComponent]
    Db/                 # persistence implementation (resource models, mappers, concrete repositories)
      MySQL/            # one storage adapter; declare additional adapters explicitly in the spec
        Model/          # *Resource, *ResourceModel, *Mapper — feature-grouping allowed
        Repository/     # concrete database-backed repositories implementing Domain/Contract/ interfaces
  Domain/
    Model/              # domain entities (NO persistence implementation here — see Application/Db)
    Repository/         # repository INTERFACES (concrete implementations live under Application/Db/<adapter>/Repository/)
    Contract/           # other domain interfaces
    Exception/          # domain exceptions
    Service/            # business rules that do not belong on one model
    Event/              # domain events (alternative to Application/Payload/Event)
  Context/              # request- / coroutine-scoped stores
  Configuration/        # readonly config classes
  composer.json
  README.md             # optional
  tests/                # optional for local modules; canonical home for module-local tests
```

A module **may** omit any of these directories. A module **may not** add unlisted directories (see § 4).

### Package module envelope

A package module root is `packages/semitexa-{name}/`. Its PHP source root is `packages/semitexa-{name}/src/`, not the package root itself.

Package root must contain:

* `composer.json`
* `src/`

Package root may contain package metadata/config files such as `LICENSE`, `README.md`, `CHANGELOG.md`, `phpunit.xml`, `phpunit.xml.dist`, `phpstan.neon`, `.gitignore`, Docker/install files, and package tooling directories such as `tests/`, `docs/`, `bin/`, `resources/`, `tools/`, `public/`, `.github/`, and `var/`.

Package `src/` follows the **same strict allowlist** as an application module's root. The only top-level directories permitted at `packages/semitexa-{name}/src/` are the canonical layers from § 2 (`Application`, `Domain`, `Context`, `Configuration`) plus `Attributes/` (package-only). There is no special "package source is package-specific" exemption — historical drift such as `Auth/`, `Discovery/`, `OpenApi/`, `Pipeline/`, `Transport/` at the package source root is rejected with `module_structure.unknown_directory`. Move such code into the appropriate canonical sub-tree (typically `Application/Service/`, `Domain/Service/`, or `Domain/Contract/`).

There is one strict package-source rule: package-owned console commands must live under `src/Application/Console/Command/`. Do not place console command classes directly at `src/Console/Command/`, `src/Console/`, `src/CLI/`, or under a feature-specific `*/Console/` directory. `src/Domain/Command/` is reserved for domain command objects only; it must not contain Symfony/Semitexa console commands. Console commands are part of the package's *application* layer and follow the same `Application/Console/Command/` shape that application modules use under `src/modules/{Name}/src/Application/Console/Command/`.

### No nested modules

A module may **not** bundle a related feature under a top-level directory that itself follows the module layout. In other words, `src/modules/{Module}/{Feature}/Application` and `src/modules/{Module}/{Feature}/Domain` are nested modules, not feature groups, and must be rejected.

```text
src/modules/Playground/
  Application/...
  Domain/...
  Graphql/             # invalid nested module
    Application/...
    Domain/...
```

If a feature needs its own `Application/` or `Domain/` tree, extract it into its own top-level module under `src/modules/` or fold its files into the parent module's canonical `Application/` and `Domain/` directories.

### Application/Service vs Domain/Service

`Application/Service/` and `Domain/Service/` are both valid, but they are not interchangeable.

Use `Application/Service/` only for application orchestration and boundary adapters:

* coordinates payload handlers, render profiles, current request/user/tenant/locale state, or framework services;
* adapts module behavior to an external framework contract such as auth, permission, routing, rendering, caching, mail, queue, or storage APIs;
* prepares data for the UI/API layer without owning business invariants;
* may depend on `Domain/*` and framework/package contracts.

Use `Domain/Service/` for business behavior owned by the module:

* enforces domain rules, permissions, state transitions, calculations, or fixture catalogs that remain meaningful without HTTP, templates, routes, or handlers;
* coordinates domain models, repositories, events, and domain contracts;
* must not depend on `Application/*`, payloads, handlers, resources, templates, current request objects, or presentation/rendering concerns.

Decision rule: if removing HTTP/routes/templates would make the class meaningless, it belongs in `Application/Service/`. If the class still expresses a module business concept with only `Domain/*` collaborators, it belongs in `Domain/Service/`. Persistence row/resource classes and mapper metadata belong under `Application/Db/`, not `Application/Service/` or `Domain/Service/`.

---

## 3. Package-only top-level directories

The following are valid only at `packages/semitexa-*/src/`:

| Directory | Purpose |
|---|---|
| `Attributes/` | Package-specific PHP attributes |
| `Update/` | Package-owned `#[AsDataPatch]` patches |

These directories are **not** valid inside `src/modules/*`. Application-module patches in `Application/Update/`.

Console commands are NOT package-only — both packages and application modules place them under `Application/Console/Command/`. A bare top-level `Console/` (or `Console/Command/`) at the root of `src/modules/{Name}/` or at `packages/semitexa-*/src/` is therefore a violation: the rule R007 catches it inside application modules, and the rule P005 catches it inside packages.

---

## 4. Validator contract

The structure validator runs as part of `bin/semitexa ai:verify` (check name: **`module_structure`**). It walks the affected module roots and emits one violation per offending directory or file.

### How affected modules are detected

For every file path the verifier receives, the detector walks parents until it finds:

1. `src/modules/{Name}/` → the application module rooted there
2. `packages/semitexa-{name}/` (with `composer.json`) → the package rooted there

If neither applies, the file is ignored by the structure check. If a file lives inside a nested module-shaped directory (e.g. `src/modules/Playground/Graphql/...`), the detector reports the **outer** module (`src/modules/Playground/`) and the validator reports the nested directory as R001.

### Validation scope

When **any** file inside a module is in the change list, the validator scans the **entire** module directory tree, not only the changed file. This is intentional: AI agents that touch one file may leave the rest of the module structurally broken.

### Determinism

For application modules, the validator never reads source code, namespaces, or class bodies for placement decisions. It uses **only**:

* directory paths
* the rule tables in § 7
* file extension and basename patterns

For package modules, rule P005 additionally reads `*Command.php` files under package `src/` to identify Symfony/Semitexa console command markers (`#[AsCommand]`, Symfony Console command base classes, or Semitexa `BaseCommand`). This is still deterministic: the verdict depends only on files in the package tree and this document's rules.

---

## 5. Issue codes

Each violation emitted by the validator carries one of these stable, AI-facing codes. Codes are constants on `Semitexa\Dev\Application\Service\Ai\Verify\Structure\ModuleStructureViolation`.

| Code | Trigger | What to do |
|---|---|---|
| `module_structure.unknown_directory` | A directory exists at a path where it is **not explicitly allowed** by the executable spec. Triggered for `Application/Payload/Response/`, `src/Endpoint/`, `src/Services/`, `src/Helpers/`, `src/Console/Command/` (in packages), and any other undeclared layer. | Move the contents into a declared sub-tree, or — if the new layer is genuinely needed — extend the spec at `packages/semitexa-dev/config/module-structure.php` (and update this doc in lockstep). Do **not** add an exception. |
| `module_structure.invalid_layer` | A package-only directory appears inside `src/modules/*`. Currently only `Attributes/` is package-only. | Application modules consume framework attributes; they do not declare them. Move into a package under `packages/semitexa-*/`. |
| `module_structure.invalid_location` | A file exists in a sub-tree whose rule does not allow files of that name (the rule's `allowAnyFile` is false and the basename is not in `allowedFiles`/`allowedFilePatterns`). | Move the file under a declared leaf path. |
| `module_structure.invalid_root_file` | A file at module root or package root is not in the spec's allowlist of metadata/config files. | Module root holds no source files; package root holds only declared metadata. Move source under `Application/<sub-tree>/` or `Domain/<sub-tree>/`. |
| `module_structure.invalid_namespace` | (Reserved) The PSR-4 namespace declared in a class file does not match its directory location. Currently emitted by companion lint commands; the structure validator surfaces directory-level violations. | Update the namespace declaration (or move the file) to match. |
| `module_structure.undeclared_path` | A path is allowed as a child of its parent rule but the spec lacks a `ModuleStructureRule` of its own — the validator cannot validate its contents. | Add a `ModuleStructureRule(path: '<the path>', …)` entry to the executable spec. |
| `module_structure.command_wrong_location` | A `*Command.php` file lives outside `Application/Console/Command/` (or any feature-grouping sub-folder of it). | Move the command class into `Application/Console/Command/` and update its namespace. The canonical location is the same for application modules (`src/modules/{Name}/src/Application/Console/Command/`) and packages (`packages/semitexa-{name}/src/Application/Console/Command/`). |
| `module_structure.missing_required_path` | A package is missing a required envelope entry (`composer.json` or `src/`). | Create the missing entry; without `composer.json` the directory is not a Semitexa package. |
| `module_structure.production_package_pollution` | A demo / sandbox / playground / example / test-app / fake / experimental folder appears anywhere inside a production package (`packages/semitexa-*/`, scanned recursively, except `tests/`). Forbidden names: `Demo`, `Demos`, `Example`, `Examples`, `Playground`, `Playgrounds`, `Sandbox`, `Sandboxes`, `Sample`, `Samples`, `TestApp`, `TestApps`, `Fake`, `Fakes`, `Experimental`, `Experiment`, `Experiments`. | Production packages must not ship demo or experimental code. Move the contents to **`src/modules/<your-demo-name>/`** (the project's local sandbox area). If the contents are test fixtures consumed by the package's `tests/`, relocate them to **`<package>/tests/Fixtures/<name>/`**. If the code is dead, delete it. **Do not** add the name to the spec — the rule is intentional. |

The validator is intentionally narrow on file content. It does **not** parse class bodies, check class-name suffixes, or evaluate namespaces — those concerns belong to the companion `lint:*` commands and the PHPStan rule set. The structure validator answers exactly one question: **is this directory or file at this path explicitly allowed by the executable spec?**

### Allowed top-level layers

The validator distinguishes three structural domains, each with its own strict allowlist:

| Domain | Path pattern | Allowlist source |
|---|---|---|
| Application module | `src/modules/{Name}/src/` (runtime) + `src/modules/{Name}/tests/` (tests) | global `top_level` minus `packageOnlyDirectories` |
| Standard package | `packages/semitexa-{name}/src/` (runtime) + `packages/semitexa-{name}/tests/` (tests) | global `top_level` ∪ `packageOnlyDirectories` |
| Framework package | `packages/semitexa-core/src/` (only) | global `top_level` ∪ `packageOnlyDirectories` ∪ `packageSpecificCodeRoot.core` |

Application modules and packages share the same envelope: runtime PHP under `src/`, tests under `tests/`. Local module roots accept only `src/`, `tests/`, and a small set of metadata files (`composer.json`, `.gitkeep`, `README.md`); anything else fires `module_structure.unknown_directory` (or `invalid_layer` for package-only layer names like `Auth/`, `Attribute/`, `Discovery/`, `OpenApi/`, `Pipeline/`).

The complete table follows. **"Allowed in packages"** means standard production packages (`semitexa-api`, `semitexa-orm`, etc.). **"Allowed in src/modules"** means application modules under `src/modules/<ModuleName>/`. Core-only entries are valid in `semitexa-core` only — they fail everywhere else with `module_structure.unknown_directory`.

#### Package source root directories

| Directory | Scope | Responsibility | Allowed in packages | Allowed in src/modules | Notes |
|---|---|---|---|---|---|
| `Application` | global | Application orchestration / payloads / handlers / resources / services / commands / templates | yes | yes | Canonical entry layer; sub-tree is also strict-allowlisted |
| `Domain` | global | Entities, domain contracts (incl. repository interfaces), domain services / events / exceptions | yes | yes | Stays clean of persistence implementation (see Application/Db). `Domain/Repository` is intentionally NOT in the allowlist — see "Repository interfaces canonical location" below. |
| `Context` | global | Request- / coroutine-scoped stores | yes | yes | Feature-grouping leaf |
| `Configuration` | global | Readonly configuration classes | yes | yes | Feature-grouping leaf |
| `Exception` | global | Package-wide exception classes | yes | yes | **Leaf — files only.** Basenames must match `*Exception.php`. No subdirectories. `ExceptionFactory.php`, `*Helper.php`, `*Service.php` are rejected. See "Exception vs Domain/Exception" below. |
| `Attribute` | package-only | Package's `#[…]` PHP attribute classes (singular form, never `Attributes`) | yes | **no** (`module_structure.invalid_layer`) | Universal package convention; canonical name is **singular** |
| `Auth` | package-only | Package's auth integration / principal types / handlers | yes | **no** | Recurring framework concept |
| `Discovery` | package-only | Discovery adapters that implement Core discovery contracts | yes | **no** | Recurring framework concept |
| `OpenApi` | package-only | OpenAPI document-generation facility (sub-trees: `Schema/`, `Route/`, `Attribute/`) | yes | **no** | Substantial framework sub-system |
| `Pipeline` | package-only | Pipeline middleware / mappers / decorators | yes | **no** | Recurring framework concept |
| `Acl` | core-only | Access-control primitives | only `semitexa-core` | no | Framework primitive |
| `Authorization` | core-only | Authorization primitives | only `semitexa-core` | no | Framework primitive |
| `CodeGen` | core-only | Code-generation infrastructure | only `semitexa-core` | no | Framework primitive |
| `Composer` | core-only | Semitexa Composer plugin | only `semitexa-core` | no | Framework infrastructure |
| `Config` | core-only | Configuration types | only `semitexa-core` | no | Framework primitive |
| `Console` (top-level) | core-only | Framework console infrastructure: Symfony console kernel (`Application.php`), abstract `BaseCommand` base class, `Runtime/` subtree | only `semitexa-core` | no | **Phase 14:** no `Command/` subdirectory. Even semitexa-core's executable commands live under `Application/Console/Command/`. See "Console infrastructure vs executable console commands" below. |
| `Container` | core-only | DI container | only `semitexa-core` | no | Framework runtime |
| `Contract` (top-level) | core-only | Framework-wide interfaces | only `semitexa-core` | no | Other layers use `Domain/Contract/` |
| `Cookie` | core-only | Cookie handling | only `semitexa-core` | no | Framework primitive |
| `Csrf` | core-only | CSRF handling | only `semitexa-core` | no | Framework primitive |
| `Error` | core-only | Error type definitions | only `semitexa-core` | no | Framework primitive |
| `Event` | core-only | Event system | only `semitexa-core` | no | Framework primitive (modules use `Domain/Event/` or `Application/Payload/Event/`) |
| `Http` | core-only | HTTP wrappers | only `semitexa-core` | no | Framework primitive |
| `Lifecycle` | core-only | Server lifecycle | only `semitexa-core` | no | Framework runtime |
| `Locale` (top-level) | core-only | Locale primitives | only `semitexa-core` | no | Other packages use `Application/View/locales/` |
| `Log` | core-only | Logging primitives | only `semitexa-core` | no | Framework primitive |
| `PHPStan` | core-only | PHPStan rule sources | only `semitexa-core` | no | Excluded from autoload classmap; only the framework ships rules |
| `Queue` | core-only | Queue primitives | only `semitexa-core` | no | Framework primitive |
| `Redis` | core-only | Redis adapter | only `semitexa-core` | no | Framework primitive |
| `Registry` | core-only | Registry pattern types | only `semitexa-core` | no | Framework primitive |
| `Request` | core-only | Request types | only `semitexa-core` | no | Framework primitive |
| `Resource` (top-level) | core-only | Resource type system | only `semitexa-core` | no | Modules use `Application/Resource/` |
| `Server` | core-only | Server runtime | only `semitexa-core` | no | Framework runtime |
| `Session` | core-only | Session primitives | only `semitexa-core` | no | Framework primitive |
| `Support` | core-only | Generic supporting utilities | only `semitexa-core` | no | Framework-internal — application code uses concrete contracts |
| `Tenant` | core-only | Tenant integration primitives | only `semitexa-core` | no | Framework primitive |
| `Theme` | core-only | Theme integration primitives | only `semitexa-core` | no | Framework primitive |
| `Validation` | core-only | Validation primitives | only `semitexa-core` | no | Framework primitive |

**Plus:** the entry-point class files at `semitexa-core/src/` root (`Application.php`, `Environment.php`, `ErrorHandler.php`, `HttpResponse.php`, `ModuleRegistry.php`, `Request.php`, `RequestFactory.php`) are core-only allowed root files. No other package may have files at its source root.

#### Validation depth modes (Phase 2)

Every declared `ModuleStructureRule` carries an explicit validation **mode**. A directory being allowed at the top level does **not** automatically permit arbitrary children. Silent skipping is forbidden.

| Mode | What the validator does for the directory's contents | When to use |
|---|---|---|
| `deep_validated` (default) | Walks children and enforces the rule's `allowedDirectories`, `allowedFiles`, `allowedFilePatterns`, `excludedFilePatterns`. `allowFeatureGrouping` and `allowAnyFile` apply as documented. Anything not allowed fires `module_structure.unknown_directory` / `module_structure.invalid_location`. | Default. Use whenever the layer's shape is known and stable. |
| `opaque_internal` | Validator does **not** scan the directory's contents. The directory is permitted by the parent rule; everything inside passes. | Only for complex framework internals (typically inside `semitexa-core`) where deep child rules have not been authored yet. **Every opaque entry must carry `opaqueReason`, `opaqueOwner`, and `opaqueTodo`.** Opaque is a *tracked deferred rule*, not an escape hatch. |
| `leaf_files_only` | Files are validated; **every** subdirectory fires `module_structure.unknown_directory`. | For leaves where feature subgrouping is architecturally wrong (e.g. a layer that holds exactly one kind of file). |

**The new violation `module_structure.opaque_marker_required` fires** if a directory is permitted at the top level by `packageSpecificCodeRoot` but no `ModuleStructureRule` exists for its path. This catches the spec gap that Phase 1's silent-skip behavior had hidden.

#### How to add a new child directory

1. Edit `packages/semitexa-dev/config/module-structure.php`.
2. Either:
   - Pick `mode: MODE_DEEP_VALIDATED` and declare `allowedDirectories` / `allowedFiles` / `allowedFilePatterns` for the new sub-tree, **or**
   - Pick `mode: MODE_OPAQUE_INTERNAL` with `opaqueReason` (why deep rules are deferred), `opaqueOwner` (team / person), and `opaqueTodo` (concrete steps to deep-validate later).
3. Update this document's table in lockstep.
4. Add a passing fixture test and a failing-sibling fixture test in `packages/semitexa-dev/tests/Unit/Ai/Verify/Structure/ModuleStructureValidatorTest.php`.
5. Run `bin/semitexa test:run`.

#### Currently opaque internal directories (deferred Phase 3 work)

Inside `semitexa-core` only. Each entry is tracked, not silently skipped.

| Directory | Owner | Reason | TODO |
|---|---|---|---|
| `Container/BuildPhase` | semitexa-core team | Container build phases are framework runtime internals | Enumerate the build-phase classes, switch to `deep_validated` |
| `Container/Store` | semitexa-core team | Container store types are framework runtime internals | Same |
| `Console/Runtime` | semitexa-core team | Console runtime is framework infrastructure | Same |
**Phase 4 final state (2026-04-30):** `$coreOpaqueDirs` is empty. Every core-only directory has an explicit `deep_validated` or `leaf_files_only` rule. The `MODE_OPAQUE_INTERNAL` mode remains a supported escape hatch for future architectural areas where deep child rules cannot be authored at first sight, but no directory currently uses it.

**Phase 3 batch 1 (2026-04-30) — promoted from `opaque_internal` to `deep_validated` / `leaf_files_only`:** `Exception`, `Event`, `Config`, `Redis`, `Support` (with drift-rejecting `excludedFilePatterns`), `Validation` (+ `Validation/Trait`), `Http` (+ `Http/Exception`, `Http/Response`), `Queue` (+ `Queue/Message`, `Queue/Transport`).

**Phase 3 batch 2 (2026-04-30) — promoted from `opaque_internal`:** `Acl`, `Authorization`, `Cookie`, `Csrf` (+ `Csrf/Attribute`), `Request` (+ `Request/Attribute`), `Session` (+ `Session/Attribute`), `Server` (+ `Server/Lifecycle`), `Resource` (+ all 9 sub-trees).

**Phase 3 batch 3 (2026-04-30) — promoted from `opaque_internal`:** `CodeGen`, `Composer`, `PHPStan` (+ `PHPStan/Rules`), `Lifecycle`, `Registry`, `Log`, `Error`. Plus tightening of `Acl` and `Authorization` with the same drift-rejecting `excludedFilePatterns` already used by `Support`.

**Phase 4 (2026-04-30) — final batch promoted from `opaque_internal`:** `Contract` (framework-level interfaces, NOT to be confused with `Domain/Contract`), `Locale`, `Tenant` (+ `Tenant/Layer`, `Tenant/Scope`), `Theme`. Plus tightening of `Event` and `Config` with the drift deny-list.

See "High-priority directories with deep rules" below for the patterns.

#### Contract vs Domain/Contract

Two layers carry the name "Contract"; they serve different purposes.

| Layer | Path | Contains | Owner |
|---|---|---|---|
| Framework `Contract` | `packages/semitexa-core/src/Contract/` | Framework-level interfaces implemented by the runtime: `TypedHandlerInterface`, `ExceptionResponseMapperInterface`, `RouteMetadataResolverInterface`, etc. Every file in this leaf ends in `Interface.php`. | semitexa-core only — gated by `packageSpecificCodeRoot.core` |
| Module `Domain/Contract` | `<package>/src/Domain/Contract/` and `src/modules/{Name}/src/Domain/Contract/` | Module-level / domain interfaces, including all repository interfaces (`*RepositoryInterface.php`). | every package + every application module |

The two never overlap: a framework `Contract/` can only appear inside `semitexa-core`; a `Domain/Contract/` can appear in any package or module. They are also at different paths so a misplaced file fails before any name conflict can arise.

Adding a new opaque entry requires the same discipline: name the reason, the owner, and the path forward. **Do not add `mode: opaque_internal` as a way to silence a violation.** The point of opaque is to make the deferral explicit, not to permit drift.

#### Attribute classes canonical location

There is **one** canonical home for PHP attribute declarations per package: **`<package>/src/Attribute/`** (singular). It accepts every PHP attribute the package owns — both general API-surface attributes (e.g. `ApiVersion`, `ExternalApi` in `semitexa-api`) AND domain-flavoured attributes that decorate specific subsystems (e.g. `CollectionFilterable`, `ProducesResourceObject` for OpenAPI endpoint metadata).

**`OpenApi/Attribute/` is forbidden.** An audit on 2026-04-30 found `Semitexa\Api\OpenApi\Attribute` as a duplicate attribute namespace alongside `Semitexa\Api\Attribute` — both contained `#[Attribute(Attribute::TARGET_CLASS)]` classes; there was no semantic distinction, only an organisational split. The dual location created ambiguity for AI agents ("which directory should I put a new attribute in?"). The duplicate was collapsed: every former `Semitexa\Api\OpenApi\Attribute\*` class moved to `Semitexa\Api\Attribute\*`. The spec now removes `Attribute` from `OpenApi/`'s allowed children.

Concretely:

| Concept | Canonical location | Notes |
|---|---|---|
| Package PHP attribute classes | `<package>/src/Attribute/` | The only allowed home. Filenames are PascalCase; basenames like `As*`, `Inject*`, plus single-word names like `Config` are all permitted. |
| OpenAPI infrastructure (schema generators, route metadata generators, document builder) | `<package>/src/OpenApi/Schema/`, `<package>/src/OpenApi/Route/`, `<package>/src/OpenApi/OpenApi*Builder.php` | OpenApi/ accepts only `Schema/` and `Route/` children. Builder/orchestrator files at `OpenApi/OpenApi*.php`. |

If a future Semitexa package genuinely needs a second attribute namespace with a different responsibility, the rule must be reintroduced **with a documented distinct purpose** — not "OpenAPI attributes". Adding `OpenApi/Attribute/` to silence a violation is wrong; the right fix is to move the attribute class to the canonical `src/Attribute/`.

#### Repository interfaces canonical location

There is **one** canonical home for repository interfaces: **`Domain/Contract/`**.

Concretely:

| Concept | Canonical location | Notes |
|---|---|---|
| Domain entities | `Domain/Model/` | persistence-named files (`*Resource.php`, `*ResourceModel.php`, `*Mapper.php`) excluded |
| Repository **interfaces** + other domain contracts | `Domain/Contract/` | basename ends in `Interface.php` |
| Concrete database-specific repository implementations | `Application/Db/<Adapter>/Repository/` | basename ends in `Repository.php` |

**`Domain/Repository/` is NOT allowed.** An audit on 2026-04-30 found zero `Domain/Repository/` files anywhere in the monorepo — it was a phantom alternative that created ambiguity for AI agents (two canonical locations for the same concept). The allowlist now removes it; using `Domain/Repository/` fires `module_structure.unknown_directory`.

If a future Semitexa convention genuinely needs `Domain/Repository/` for a different responsibility, the rule must be reintroduced **with a documented distinct purpose** (not "repository interfaces").

#### High-priority directories with deep rules

Beyond Application/Db (already covered above), the following layers have explicit Phase 2 file-pattern rules:

| Path | Allowed file patterns | Excluded file patterns | Notes |
|---|---|---|---|
| `Application/Console/Command` | `/^[A-Z][A-Za-z0-9_]*Command\.php$/` | — | Only `*Command.php` basenames; `allowAnyFile` removed |
| `Domain/Model` | (any PascalCase via `allowAnyFile: true`) | `/(Resource\|ResourceModel\|Mapper)\.php$/` | Persistence resource models / mappers go to `Application/Db/<Adapter>/Model/`, not here |
| `Domain/Contract` | `/^[A-Z][A-Za-z0-9_]*Interface\.php$/` | — | The single canonical home for domain interfaces, **including repository interfaces**. No concrete implementations. |
| `Domain/Exception` | `/^[A-Z][A-Za-z0-9_]*Exception\.php$/` | — | Exception classes only |
| `Attribute` | `/^[A-Z][A-Za-z0-9_]*\.php$/` (PascalCase class file) | `/^(?!(As\|Inject))[A-Z][A-Za-z0-9_]*Command\.php$/` (reject misplaced commands) | Attribute classes; `*Command.php` basenames other than `As*Command` / `Inject*Command` are rejected here so the file-placement rule's intent isn't bypassed |
| `Container` | `/^[A-Z][A-Za-z0-9_]*\.php$/` + exact files `[README.md]` | — | Children: `BuildPhase/`, `Exception/`, `Store/` only |
| `Console` (top-level, semitexa-core) | exact files `[Application.php, BaseCommand.php]` | — | **Phase 14:** `Command/` removed. Children: `Runtime/` only. Top-level `Console/` is **framework console infrastructure** — the Symfony console kernel (`Application.php`) and the abstract `BaseCommand` base class. Executable commands live exclusively under `Application/Console/Command/`, including in semitexa-core itself. |
| `Domain/Command` | `/^[A-Z][A-Za-z0-9_]*Command\.php$/` | — | **Phase 14:** CQRS-style **domain command DTOs** (immutable `final readonly class` message objects describing an intent — e.g. `StartWorkflowCommand`). NOT for executable console commands. The global `*Command.php` file-placement rule treats this path as exempt (`exemptUnderPaths`), so a basename ending in `Command.php` here does not double-fire `module_structure.command_wrong_location`. |
| `Exception` (top-level, semitexa-core) | `/^[A-Z][A-Za-z0-9_]*Exception\.php$/` | — | LEAF — no subdirectories. Phase 3 batch 1. |
| `Event` (top-level, semitexa-core) | `/^[A-Z][A-Za-z0-9_]*\.php$/` | — | LEAF — no subdirectories. Phase 3 batch 1. |
| `Config` (top-level, semitexa-core) | `/^[A-Z][A-Za-z0-9_]*\.php$/` | — | LEAF — no subdirectories. Phase 3 batch 1. |
| `Redis` (top-level, semitexa-core) | `/^Redis[A-Z][A-Za-z0-9_]*\.php$/` | — | LEAF — adapter prefix; no subdirectories. Phase 3 batch 1. |
| `Support` (top-level, semitexa-core) | `/^[A-Z][A-Za-z0-9_]*\.php$/` | — | LEAF — no subdirectories. Phase 3 batch 1. |
| `Validation` (top-level, semitexa-core) | — | — | Children: `Trait/` only. Phase 3 batch 1. |
| `Validation/Trait` (semitexa-core) | `/^[A-Z][A-Za-z0-9_]*Trait\.php$/` | — | LEAF — basename ends in Trait.php. Phase 3 batch 1. |
| `Http` (top-level, semitexa-core) | `/^[A-Z][A-Za-z0-9_]*\.php$/` | — | Children: `Exception/`, `Response/` only + PascalCase root files. Phase 3 batch 1. |
| `Http/Exception` (semitexa-core) | `/^[A-Z][A-Za-z0-9_]*Exception\.php$/` | — | LEAF. Phase 3 batch 1. |
| `Http/Response` (semitexa-core) | `/^[A-Z][A-Za-z0-9_]*\.php$/` | — | LEAF. Phase 3 batch 1. |
| `Queue` (top-level, semitexa-core) | `/^[A-Z][A-Za-z0-9_]*\.php$/` | — | Children: `Message/`, `Transport/` only + PascalCase root files. Phase 3 batch 1. |
| `Queue/Message` (semitexa-core) | `/^[A-Z][A-Za-z0-9_]*Message\.php$/` | — | LEAF — basename ends in Message.php. Phase 3 batch 1. |
| `Queue/Transport` (semitexa-core) | `/^[A-Z][A-Za-z0-9_]*Transport(?:Factory)?\.php$/` | — | LEAF — Transport / TransportFactory naming. Phase 3 batch 1. |
| `Support` (semitexa-core) | `/^[A-Z][A-Za-z0-9_]*\.php$/` | `/(Helper\|Helpers\|Util\|Utils\|Manager\|Managers\|Misc\|Common)\.php$/` | LEAF. Phase 3 batch 1 + tightening: drift-rejecting deny-list keeps Support from becoming a Helpers/Utils dumping ground. |
| `Acl` (semitexa-core) | `/^[A-Z][A-Za-z0-9_]*\.php$/` | — | LEAF. Phase 3 batch 2. |
| `Authorization` (semitexa-core) | `/^[A-Z][A-Za-z0-9_]*\.php$/` | — | LEAF. Phase 3 batch 2. |
| `Cookie` (semitexa-core) | `/^Cookie[A-Z][A-Za-z0-9_]*\.php$/` | — | LEAF — basenames start with Cookie. Phase 3 batch 2. |
| `Csrf` (semitexa-core) | `/^Csrf[A-Z][A-Za-z0-9_]*\.php$/` | — | Children: `Attribute/` only. Root files start with Csrf. Phase 3 batch 2. |
| `Csrf/Attribute` (semitexa-core) | `/^[A-Z][A-Za-z0-9_]*\.php$/` | — | LEAF. PHP attribute classes. Phase 3 batch 2. |
| `Request` (semitexa-core) | — | — | Children: `Attribute/` only. No root files allowed. Phase 3 batch 2. |
| `Request/Attribute` (semitexa-core) | `/^[A-Z][A-Za-z0-9_]*\.php$/` | — | LEAF. PHP attribute classes (PathParam, …). Phase 3 batch 2. |
| `Session` (semitexa-core) | `/^[A-Z][A-Za-z0-9_]*\.php$/` | — | Children: `Attribute/` only + PascalCase root files. Phase 3 batch 2. |
| `Session/Attribute` (semitexa-core) | `/^[A-Z][A-Za-z0-9_]*\.php$/` | — | LEAF. Phase 3 batch 2. |
| `Server` (semitexa-core) | `/^[A-Z][A-Za-z0-9_]*\.php$/` | — | Children: `Lifecycle/` only + PascalCase root files. Phase 3 batch 2. |
| `Server/Lifecycle` (semitexa-core) | `/^Server[A-Z][A-Za-z0-9_]*\.php$/` | — | LEAF — basenames start with Server. Phase 3 batch 2. |
| `Resource` (semitexa-core) | `/^[A-Z][A-Za-z0-9_]*\.php$/` | — | Children: `Attribute/`, `Cursor/`, `Exception/`, `Filter/`, `Lifecycle/`, `Memo/`, `Metadata/`, `Pagination/`, `Sort/` only + PascalCase root files. Phase 3 batch 2. |
| `Resource/Attribute` (semitexa-core) | `/^[A-Z][A-Za-z0-9_]*\.php$/` | — | LEAF. PHP attribute classes for resources. Phase 3 batch 2. |
| `Resource/Cursor` (semitexa-core) | `/^[A-Z][A-Za-z0-9_]*Cursor[A-Za-z0-9_]*\.php$/` | — | LEAF — basenames contain Cursor. Phase 3 batch 2. |
| `Resource/Exception` (semitexa-core) | `/^[A-Z][A-Za-z0-9_]*Exception\.php$/` | — | LEAF — basenames end in Exception.php. Phase 3 batch 2. |
| `Resource/Filter` (semitexa-core) | `/^[A-Z][A-Za-z0-9_]*\.php$/` | — | LEAF. Phase 3 batch 2. |
| `Resource/Lifecycle` (semitexa-core) | `/^[A-Z][A-Za-z0-9_]*Listener\.php$/` | — | LEAF — basenames end in Listener.php. Phase 3 batch 2. |
| `Resource/Memo` (semitexa-core) | `/^[A-Z][A-Za-z0-9_]*Store\.php$/` | — | LEAF — basenames end in Store.php. Phase 3 batch 2. |
| `Resource/Metadata` (semitexa-core) | `/^[A-Z][A-Za-z0-9_]*\.php$/` | — | LEAF. Phase 3 batch 2. |
| `Resource/Pagination` (semitexa-core) | `/^Collection[A-Z][A-Za-z0-9_]*\.php$/` | — | LEAF — basenames start with Collection. Phase 3 batch 2. |
| `Resource/Sort` (semitexa-core) | `/^[A-Z][A-Za-z0-9_]*\.php$/` | — | LEAF. Phase 3 batch 2. |
| `CodeGen` (semitexa-core) | `/^[A-Z][A-Za-z0-9_]*Generator\.php$/` | — | LEAF — basenames end in Generator.php. Phase 3 batch 3. |
| `Composer` (semitexa-core) | `/^[A-Z][A-Za-z0-9_]*Plugin\.php$/` | — | LEAF — basenames end in Plugin.php. Phase 3 batch 3. |
| `PHPStan` (semitexa-core) | — | — | Children: `Rules/` only. No root files. Phase 3 batch 3. |
| `PHPStan/Rules` (semitexa-core) | `/^[A-Z][A-Za-z0-9_]*Rule\.php$/` | — | LEAF — basenames end in Rule.php. Phase 3 batch 3. |
| `Lifecycle` (semitexa-core) | `/^[A-Z][A-Za-z0-9_]*\.php$/` | `/(Helper\|Helpers\|Util\|Utils\|Manager\|Managers\|Misc\|Common)\.php$/` | LEAF + drift deny-list. Phase 3 batch 3. |
| `Registry` (semitexa-core) | `/^[A-Z][A-Za-z0-9_]*\.php$/` | `/(Helper\|Helpers\|Util\|Utils\|Manager\|Managers\|Misc\|Common)\.php$/` | LEAF + drift deny-list. Phase 3 batch 3. |
| `Log` (semitexa-core) | `/^[A-Z][A-Za-z0-9_]*\.php$/` | `/(Helper\|Helpers\|Util\|Utils\|Manager\|Managers\|Misc\|Common)\.php$/` | LEAF + drift deny-list. Phase 3 batch 3. |
| `Error` (semitexa-core) | `/^Error[A-Z][A-Za-z0-9_]*\.php$/` | — | LEAF — basenames must start with Error. Phase 3 batch 3. |
| `Acl` (semitexa-core) | `/^[A-Z][A-Za-z0-9_]*\.php$/` | `/(Helper\|Helpers\|Util\|Utils\|Manager\|Managers\|Misc\|Common)\.php$/` | Phase 3 batch 3 tightening: drift deny-list added. |
| `Authorization` (semitexa-core) | `/^[A-Z][A-Za-z0-9_]*\.php$/` | `/(Helper\|Helpers\|Util\|Utils\|Manager\|Managers\|Misc\|Common)\.php$/` | Phase 3 batch 3 tightening: drift deny-list added. |
| `Contract` (top-level, semitexa-core) | `/^[A-Z][A-Za-z0-9_]*Interface\.php$/` | — | LEAF. Framework-level interfaces. **NOT** the same as Domain/Contract — see "Contract vs Domain/Contract" above. Phase 4. |
| `Locale` (semitexa-core) | `/^[A-Z][A-Za-z0-9_]*\.php$/` | `/(Helper\|Helpers\|Util\|Utils\|Manager\|Managers\|Misc\|Common)\.php$/` | LEAF + drift deny-list. Phase 4. |
| `Tenant` (semitexa-core) | `/^[A-Z][A-Za-z0-9_]*\.php$/` | `/(Helper\|Helpers\|Util\|Utils\|Manager\|Managers\|Misc\|Common)\.php$/` | Children: `Layer/`, `Scope/` only + PascalCase root files. Drift deny-list. Phase 4. |
| `Tenant/Layer` (semitexa-core) | `/^[A-Z][A-Za-z0-9_]*(Layer\|Value\|Interface)\.php$/` | — | LEAF — basenames end in Layer.php / Value.php / Interface.php. Phase 4. |
| `Tenant/Scope` (semitexa-core) | `/^[A-Z][A-Za-z0-9_]*Scope[A-Za-z0-9_]*\.php$/` | — | LEAF — basenames must contain Scope. Phase 4. |
| `Theme` (semitexa-core) | `/^Theme[A-Z][A-Za-z0-9_]*\.php$/` | — | LEAF — basenames must start with Theme so the layer cannot become a generic frontend dumping ground. Phase 4. |
| `Event` (semitexa-core) | `/^[A-Z][A-Za-z0-9_]*\.php$/` | `/(Helper\|Helpers\|Util\|Utils\|Manager\|Managers\|Misc\|Common)\.php$/` | Phase 4 tightening: drift deny-list added (was: Phase 3 batch 1 with no exclusions). |
| `Config` (semitexa-core) | `/^[A-Z][A-Za-z0-9_]*\.php$/` | `/(Helper\|Helpers\|Util\|Utils\|Manager\|Managers\|Misc\|Common)\.php$/` | Phase 4 tightening: drift deny-list added. |

#### Catches

* **No wildcard.** The "core-only" set is named explicitly in `packageSpecificCodeRoot.core` — no glob, no catch-all.
* **No transitive permission.** A directory like `Container` works only inside `semitexa-core`; using it inside `semitexa-api` fires `module_structure.unknown_directory`.
* **Singular `Attribute`.** The plural form `Attributes` is rejected everywhere — there is one outlier in `semitexa-graphql` and that is drift.
* **Top-level `Console` is not for app commands.** Even in packages where `Console/` would be valid (currently only `semitexa-core`), it is for **framework console infrastructure** (`BaseCommand`, console `Application`). Application commands always live under `Application/Console/Command/`. Other packages currently shipping a top-level `Console/` are drift; the validator will fail when they are touched.
* **Nested module-shaped directories** (`Graphql/Application/…`, `Endpoint/…`, `Showcase/…`) and invented architectural folders (`Services/`, `Helpers/`, `Managers/`, `Common/`, `Misc/`, `Util/`, `Db/` at the top level, `Console/Command/` outside `Application/`) all fail with `module_structure.unknown_directory`. None are exceptions.

### Production packages must not ship demo / sandbox code

Production packages (`packages/semitexa-*/`) are scanned **recursively** for forbidden names (`Demo`, `Demos`, `Example`, `Examples`, `Playground`, `Playgrounds`, `Sandbox`, `Sandboxes`, `Sample`, `Samples`, `TestApp`, `TestApps`, `Fake`, `Fakes`, `Experimental`, `Experiment`, `Experiments`). A match anywhere fires `module_structure.production_package_pollution`. The `tests/` sub-tree is the only exception — it is out of scope for the structure validator (see § 10) and may freely contain `tests/Fixtures/Demo/` etc.

The canonical home for local demo, sandbox, and proof-of-concept code is **`src/modules/<your-demo-name>/`** — the project's local sandbox area where application modules live. The pollution rule never fires on `src/modules/Demo/` or `src/modules/Playground/` because application modules under `src/modules/` may carry any name (subject to the canonical layout inside).

The rule is intentional and absolute: **do not** add forbidden names to the spec to "fix" a violation. Move the code to `src/modules/` or to `<package>/tests/Fixtures/`, or delete it.

### Allowed Application children

Allowed direct children of `Application/`: `Payload`, `Resource`, `Handler`, `Service`, `Console`, `Update`, `Static`, `View`, `Component`, `Db`, `Enum`. `Command` is **not** allowed at this level — console commands live under `Application/Console/Command/` (see file-placement rule below). `Application/Enum/` holds application/runtime enums (orchestration modes, runtime states, pipeline phases) — see "Enum placement" above. Sub-feature grouping inside a leaf (e.g. `Application/Service/Customer/`, `Application/Resource/Response/Customer/`) is permitted because those leaves declare `allowFeatureGrouping: true` in the spec; `Application/Enum/` is leaf-only and does **not** allow sub-folders.

### Application/Db — persistence implementation layer

`Application/Db/` is the **canonical home for persistence implementation classes**: resource models, persistence mappers, and concrete database-backed repository implementations.

**The split is deliberate:**

* `Domain/` holds the *domain* — entities (`Domain/Model/`), repository **interfaces** (`Domain/Repository/`), other domain contracts (`Domain/Contract/`), domain services (`Domain/Service/`). It must stay clean of infrastructure: no MySQL specifics, no resource model classes, no concrete repositories.
* `Application/Db/` holds the *implementation* — the storage-specific classes that satisfy the domain interfaces.

**Officially supported ORM adapters** (from `packages/semitexa-orm/src/Adapter/` — `MysqlAdapter`, `SqliteAdapter` — and the runtime guard in `Semitexa\Orm\OrmManager` that rejects every other driver string):

| Adapter directory | Driver string | ORM adapter class | ORM type enum |
|---|---|---|---|
| `Application/Db/MySQL/`  | `mysql`  | `Semitexa\Orm\Adapter\MysqlAdapter`  | `Semitexa\Orm\Adapter\MySqlType`  |
| `Application/Db/SQLite/` | `sqlite` | `Semitexa\Orm\Adapter\SqliteAdapter` | `Semitexa\Orm\Adapter\SqliteType` |

The directory list is **closed**: only these adapter directory names are accepted under `Application/Db/`. Any other name (`Postgres`, `PostgreSQL`, `Oracle`, `MongoDB`, `Custom`, `MariaDB`) fails with `module_structure.unknown_directory`. There is no wildcard.

**Allowed sub-tree per adapter:**

| Path | Contains | Allowed file patterns |
|---|---|---|
| `Application/Db/<Adapter>/Model/`      | `*Resource` and `*ResourceModel` classes — the ORM-facing schema. Feature grouping (`Application/Db/<Adapter>/Model/Customer/…`) is allowed. | `*Resource.php`, `*ResourceModel.php` only — `*Mapper.php` belongs in the peer `Mapper/` sub-tree; `SomeService.php` / `SomeHelper.php` / `SomeManager.php` etc. fail with `module_structure.invalid_location`. |
| `Application/Db/<Adapter>/Mapper/`     | `*Mapper` classes — translate between resource models and domain entities. Peer of `Model/`, not a child of it. Feature grouping (`Application/Db/<Adapter>/Mapper/Customer/…`) is allowed. | `*Mapper.php` only — `*Resource.php` / `*ResourceModel.php` / `SomeService.php` etc. fail with `module_structure.invalid_location`. |
| `Application/Db/<Adapter>/Repository/` | Concrete repository implementations that implement the interfaces under `Domain/Repository/` (or `Domain/Contract/`). Feature grouping allowed. | `*Repository.php` only — `SomeFactory.php` / `SomeHelper.php` / `SomeService.php` fail with `module_structure.invalid_location`. |

**Adding a new storage adapter** requires three coordinated edits:

1. Ship the adapter class in `packages/semitexa-orm/src/Adapter/` (e.g. `PostgresAdapter` + `PostgresType`).
2. Add the adapter directory name (e.g. `Postgres` or `PostgreSQL` — pick one and use it consistently) to the `$persistenceAdapters` list in `packages/semitexa-dev/config/module-structure.php`. The validator derives all `Application/Db/<Adapter>/...` rules from that single list — no per-adapter rule duplication.
3. Add a row to the table above and update the prose.

Until all three are done, the validator rejects the new adapter directory.

**Anti-pattern (will fail):**

* `Domain/Model/MachineCredentialResource.php` — `*Resource`/`*ResourceModel` classes are persistence implementation; they belong under `Application/Db/<Adapter>/Model/`, not `Domain/Model/`. (`Domain/Model/` holds entity types only.)
* `Domain/Repository/MachineCredentialMysqlRepository.php` — concrete database-specific repositories belong under `Application/Db/<Adapter>/Repository/`, not `Domain/Repository/`. (`Domain/Repository/` holds the interfaces.)
* `Application/Db/Oracle/Model/UserResource.php` — Oracle is not in the supported adapter list; the directory is rejected.
* `Application/Db/MySQL/Model/UserService.php` — `Service` is not a persistence model class; rename to a `*Resource`/`*ResourceModel` or move to `Application/Service/`.
* `Application/Db/MySQL/Model/UserMapper.php` — mappers are a peer sub-tree, not a child of `Model/`; move to `Application/Db/MySQL/Mapper/UserMapper.php`.
* `Application/Db/MySQL/Repository/UserFactory.php` — `Factory` is not a repository class; rename to `*Repository.php` or move out of the persistence layer.

### Allowed Application/Console children

`Application/Console/` accepts exactly one child: `Command`. Nothing else lives there.

### Allowed Domain children

`Domain/` accepts: `Model`, `Contract`, `Exception`, `Service`, `Event`, `Command`, `Enum`. Each is a feature-grouping leaf (sub-features like `Customer/`, `Order/` are allowed) **except** `Domain/Enum/`, which is leaf-only. `Domain/Repository/` is **not** in this list — repository interfaces live in `Domain/Contract/` and concrete implementations in `Application/Db/<Adapter>/Repository/`. `Domain/Command/` is for **CQRS-style domain command DTOs** (immutable message objects) — see "Console infrastructure vs executable console commands" below. `Domain/Enum/` is for domain-semantic enums — see "Enum placement" below.

### Enum placement

Enums are placed **contextually**, not in a single bucket. There is **no** top-level `src/Enum/` — that fails with `module_structure.unknown_directory`.

| Path | When to use | Naming | Mode |
|---|---|---|---|
| `<module-root>/Domain/Enum/` | **Domain/business semantics** — matching strategies, scope semantics, field-type taxonomies, status taxonomies, ranking concepts. Anything used by domain contracts/models. | PascalCase concept name (e.g. `SearchScope`, `SearchFieldType`, `MediaKind`, `WebhookDirection`). **No `*Enum` suffix.** | `leaf_files_only` |
| `<module-root>/Application/Enum/` | **Application/runtime/orchestration modes** — runtime execution states, framework adapter modes, pipeline phases, dispatch modes. | Same convention as Domain/Enum. | `leaf_files_only` |

Both rules:

- Leaf-only — **no sub-directories**.
- File pattern: `/^[A-Z][A-Za-z0-9_]*\.php$/` (PascalCase).
- Drift deny-list: filenames ending in `Helper`, `Util`, `Manager`, `Misc`, `Common`, `Service`, `Factory`, `Provider`, `Adapter`, `Builder`, `Resolver` are rejected with `module_structure.invalid_location`. None of those are enums.
- The codebase convention does **not** use a `*Enum` filename suffix — enum names are bare PascalCase concept names (`HttpStatus`, `BootPhase`, `RunStatus`, …). The spec mirrors this.

**Rule of thumb:** if the enum encodes "what is this *thing*" (domain semantics — taxonomy, status, scope, kind), it goes in `Domain/Enum/`. If it encodes "how should the system *behave*" (runtime/adapter mode, pipeline phase, execution policy), it goes in `Application/Enum/`. When in doubt, prefer `Domain/Enum/` — most enums are part of the domain language.

> *Future work:* the leaf rules currently filter by filename pattern only. A planned content-aware enhancement will require each file to declare a PHP `enum` to close the gap between "non-drift filename" and "actually a PHP enum class."

### Exception vs Domain/Exception

Exception classes have **two valid canonical homes** with distinct intent:

| Path | When to use | Mode |
|---|---|---|
| `<module-root>/Exception/` | **Default.** Package-wide exception classes — the normal place for any thrown type a package or application module owns. | `leaf_files_only`, `*Exception.php` only |
| `<module-root>/Domain/Exception/` | Only when the package architecture explicitly distinguishes domain-only invariant violations (DDD-style aggregates with a domain-layer exception family) from broader runtime / adapter / configuration exceptions. | feature-grouping leaf, `*Exception.php` only |

**Rule of thumb:** if you'd struggle to articulate why an exception is "domain-only and not just a package exception," it belongs at top-level `Exception/`. Most Semitexa packages do not need `Domain/Exception/` and use only `Exception/`.

**Top-level `Exception/` is strict:**

- Leaf only — **no sub-directories**. `Exception/Internal/FooException.php` fails with `module_structure.unknown_directory`.
- Filename pattern `*Exception.php` only. `ExceptionFactory.php`, `FooHelper.php`, `FooService.php`, `ErrorHandler.php`, `FooUtil.php` all fail with `module_structure.invalid_location`.
- No exception-related services, handlers, factories, helpers, or generic utility classes belong here. Those go to `Application/Service/`, `Application/Handler/`, etc.

The same pattern enforcement applies under `Domain/Exception/` (`*Exception.php` basenames only), with the additional difference that `Domain/Exception/` permits feature-grouping sub-folders.

### Console infrastructure vs executable console commands

The validator separates three things that all involve the word "Command" but live in three different places:

| Concept | Canonical home | Notes |
|---|---|---|
| **Executable console command** (`#[AsCommand]` class invoked from `bin/semitexa`) | `Application/Console/Command/` (every package, including `semitexa-core`) | Phase 14: there is **no** core-only exception. Even semitexa-core's `CacheClearCommand`, `RoutesListCommand`, etc., live here. |
| **Console infrastructure** (Symfony console kernel, abstract `BaseCommand` base class) | `Console/` (top-level, **semitexa-core only**) — exact files `Application.php` + `BaseCommand.php`, plus `Console/Runtime/` subtree | These are framework primitives. Top-level `Console/` is core-only via `packageSpecificCodeRoot` and explicitly does **not** allow a `Command/` subdirectory. |
| **CQRS domain command DTO** (`final readonly class` describing an intent — e.g. `StartWorkflowCommand`, `ApplyTransitionCommand`) | `Domain/Command/` (any module / package) | These are message objects, not invokable commands. Their basenames legitimately end in `Command.php` but they belong in the domain layer. The global file-placement rule treats `Domain/Command/` as **exempt** so the same basename pattern does not double-trigger `module_structure.command_wrong_location`. **Not a loophole:** the validator reads file content under `Domain/Command/` and revokes the exemption when the file actually behaves like an executable console command — see "Domain/Command is not a loophole" below. |

Why three buckets? Each role has distinct ownership and consumer surface:

- An *executable* command is loaded by the console runtime; placing it in `Domain/Command/` would silently break console discovery and tangle the domain layer with infrastructure.
- An *infrastructure* base class (`BaseCommand`) is consumed via inheritance by every executable command across packages; placing it under `Application/Console/Command/` would wrongly suggest it is itself a runnable command.
- A *CQRS DTO* is part of the domain message contract and must stay free of console / Symfony coupling.

Rule of thumb: if it has `#[AsCommand]` or extends Symfony's `Command`, it belongs in `Application/Console/Command/`. If it is `final readonly class FooCommand` describing a domain intent, it belongs in `Domain/Command/`. If neither, it is probably a service.

### File-placement rule for console commands

A file whose basename matches `*Command.php` MUST live at (or under) `Application/Console/Command/`. This rule is global — it fires regardless of whether the file's parent directory is declared. So:

- `Application/Service/SyncCommand.php` → `module_structure.command_wrong_location`
- `Application/Console/Command/Customer/SyncCommand.php` → OK (feature subfolder)
- `Console/Command/SyncCommand.php` → both `module_structure.unknown_directory` (`Command/` not in top-level `Console.allowedDirectories`) AND `module_structure.command_wrong_location` (the file itself is misplaced). This holds in **every** package, including `semitexa-core`.
- `Domain/Command/StartWorkflowCommand.php` → OK (CQRS DTO; `Domain/Command/` is exempt). The per-directory rule still enforces the `*Command.php` PascalCase pattern, so a misplaced `FooService.php` here fails with `module_structure.invalid_location`.

#### Domain/Command is not a loophole

The exemption is **path-only on its surface and content-aware in practice**. When a `*Command.php` file lies under `Domain/Command/`, the validator opens the file and inspects its content; if any of the following signals are present, the exemption is **revoked** and `module_structure.command_wrong_location` fires anyway:

| Signal | Pattern (PCRE, against file content) |
|---|---|
| Carries Semitexa's `#[AsCommand]` attribute | `/#\[\s*(?:[A-Za-z_\\\\][A-Za-z0-9_\\\\]*\\\\)?AsCommand\b/` |
| Extends `Semitexa\Core\Console\BaseCommand` (bare or fully-qualified) | `/\bextends\s+(?:[A-Za-z_\\\\][A-Za-z0-9_\\\\]*\\\\)?BaseCommand\b/` |
| References Symfony's console base class FQCN anywhere | `/Symfony\\Component\\Console\\Command\\Command\b/` |

So:

- `Domain/Command/StartWorkflowCommand.php` containing `final readonly class StartWorkflowCommand` → OK (no signal).
- `Domain/Command/FooCommand.php` carrying `#[AsCommand(name: 'foo')]` → `module_structure.command_wrong_location`.
- `Domain/Command/FooCommand.php` declaring `extends BaseCommand` or `extends Semitexa\Core\Console\BaseCommand` → `module_structure.command_wrong_location`.
- `Domain/Command/FooCommand.php` carrying `use Symfony\Component\Console\Command\Command;` → `module_structure.command_wrong_location`.

The signals are intentionally narrow — they target the *console kernel* coupling, not the word "command." A pure domain DTO never has a reason to import the Symfony console namespace, extend `BaseCommand`, or carry `#[AsCommand]`.

The detection is implemented via `FilePlacementRule::forbiddenContentPatternsUnderExempt` — see `packages/semitexa-dev/src/Ai/Verify/Structure/FilePlacementRule.php`.

---

## 6. Diagnostic shape

When the validator fails, `ai:verify` emits NDJSON `violation` events. Each carries enough information for an agent to fix without guessing.

```jsonc
{
  "kind":           "violation",
  "check":          "module_structure",
  "severity":       "error",
  "rule":           "module_structure.unknown_directory",
  "code":           "module_structure.unknown_directory",
  "module":         "src/modules/Playground",
  "path":           "src/modules/Playground/Application/Db",
  "message":        "Unknown direct child of Application/: 'Db'.",
  "expected":       "one of: Payload, Resource, Handler, Service, Command, Update, Static, View, Component",
  "actual":         "Db/",
  "doc_ref":        "packages/semitexa-docs/docs/MODULE_STRUCTURE.md#5-rules",
  "suggested_fix":  "Move persistence code to Domain/Repository/ or to a domain-specific Service/. Storage adapters belong under Domain/, not Application/."
}
```

`ai:verify` aggregates all violations in the JSON envelope under `violations`. The result line for the corresponding `module_structure` target is `status: fail` whenever any violation has `severity: error`.

---

## 7. Executable specification

The validator does **not** parse this Markdown. It loads the executable spec at:

```
packages/semitexa-dev/config/module-structure.php
```

That file `return`s a `ModuleStructureSpec` value containing `ModuleStructureRule` entries — one per declared path. **It is the authoritative source.** This document mirrors it for human readers; when one changes the other must change in lockstep.

### Why an executable spec instead of fenced Markdown blocks?

* Strict allowlist semantics need primitives like "any file allowed at this leaf" (`allowAnyFile: true`), "any sub-folder name allowed" (`allowFeatureGrouping: true`), "files matching this PCRE pattern are allowed", "this file basename pattern must live under this required path". Fenced text blocks could not express that without inventing yet another mini-language.
* Loading is `require` — zero parse cost, no fragile regex.
* PHP gives type-checked rule construction; a typo in a path or a mistyped flag is a runtime error, not a silently-ignored line.

### How to edit the spec

1. Open `packages/semitexa-dev/config/module-structure.php`.
2. Add a `new ModuleStructureRule(path: 'X/Y', allowedDirectories: [...], …)` entry. If the new path is a sub-tree, also list it in the parent rule's `allowedDirectories`.
3. Update this Markdown's § 2 (canonical tree) and § 5 (allowed children) to match.
4. Add a regression test in `packages/semitexa-dev/tests/Unit/Ai/Verify/Structure/ModuleStructureValidatorTest.php` proving the new shape passes and an obviously-wrong sibling fails.
5. Run `bin/semitexa test:run`.

### What the spec encodes

* **`codeRootRules`** — `ModuleStructureRule` per path inside the module's code root (`src/modules/{Name}/` or `packages/semitexa-{name}/src/`). The synthetic key `top_level` is the code root itself.
* **`packageRootRule`** — what the package filesystem root (`packages/semitexa-{name}/`) may contain: directories like `src`, `tests`, `docs`, `bin`, …; metadata files like `composer.json`, `LICENSE`, `phpunit.xml.dist`; and pattern allowlists for `README.md`, `docker-compose.*.yml`, etc.
* **`filePlacement`** — global file-name → required-path rules. Currently: `*Command.php` → `Application/Console/Command`. A miss emits `module_structure.command_wrong_location`.
* **`packageOnlyDirectories`** — directory names valid at a package code root but rejected inside `src/modules/*`. Currently: `Attribute`, `Auth`, `Discovery`, `OpenApi`, `Pipeline`. Each is a named framework layer; each entry is narrow and explicit (no catch-all). Inside `src/modules/*`, using any of these fires `module_structure.invalid_layer`.
* **`requiredPackageRootEntries`** — entries that MUST exist at every package root (currently `composer.json` and `src`). A miss emits `module_structure.missing_required_path`.
* **`forbiddenInProductionPackages`** — directory names that must NEVER appear anywhere inside a `packages/semitexa-*/` tree (case-insensitive match, `tests/` subtree exempted). Currently: `Demo`, `Demos`, `Example`, `Examples`, `Playground`, `Playgrounds`, `Sandbox`, `Sandboxes`, `Sample`, `Samples`, `TestApp`, `TestApps`, `Fake`, `Fakes`, `Experimental`, `Experiment`, `Experiments`. A match fires `module_structure.production_package_pollution` with a fix that points at `src/modules/<your-demo-name>/`. Adding a name to this list is how a new demo-style anti-pattern gets caught project-wide; **removing** a name from this list (to "fix" a violation) is wrong and contradicts the rule's intent.
* **`packageSpecificCodeRoot`** — per-package additional allowlist for the package's source root. Map keyed by package short name (`'core'` for `packages/semitexa-core`). Each entry has `directories` and `files` lists. Used for narrow framework-only layers (`Container`, `Composer`, `Lifecycle`, top-level `Console`, `PHPStan`, …) and the entry-point class files at `semitexa-core/src/` root. **Not** a wildcard — every entry is named explicitly. A directory listed here is permitted at that package's source root and treated as an unvalidated leaf (its contents are not scanned by the structure check). The same name in a different package fires `module_structure.unknown_directory`.

### There is no escape hatch

The previous version of this document had a `semitexa-module-known-violations` allowlist for grandfathered drift. **It is removed.** Strict allowlist validation has no per-path exemptions: either the path is declared in the spec, or it fails. If existing code violates the spec, the right fix is to refactor the code, not to grandfather the violation.

---

## 8. Examples

### Valid

```text
src/modules/Hello/
  Application/Payload/Request/IndexPayload.php
  Application/Resource/Response/IndexResource.php
  Application/Handler/PayloadHandler/IndexHandler.php
  Application/View/templates/pages/index.html.twig
```

### Invalid

```text
packages/semitexa-api/src/Domain/Model/MachineCredentialResource.php
                          ^^ wrong layer: persistence resource models belong under Application/Db/<adapter>/Model/
                             — Domain/Model/ is for entities only
```

```text
packages/semitexa-api/src/Application/Db/SQLite/Model/...
                                          ^^ module_structure.unknown_directory: 'SQLite/' is not a declared adapter
                             — declare it explicitly in the spec or use the existing MySQL/ adapter
```

```text
src/modules/Playground/Graphql/Application/Payload/...
                       ^^ module_structure.unknown_directory: 'Graphql/' not declared as a top-level layer
```

```text
src/modules/SomeApp/Endpoint/GraphqlPayload.php
                    ^^ module_structure.unknown_directory: 'Endpoint/' is not a declared top-level layer
```

```text
src/modules/Hello/Showcase/...
                  ^^ module_structure.unknown_directory: demo pages belong under Application/<sub-tree>/
```

```text
src/modules/Hello/MyHelper.php
                  ^^ module_structure.invalid_root_file: source files belong under Application/<sub-tree>/ or Domain/<sub-tree>/
```

```text
packages/semitexa-api/src/Console/Command/DumpOpenApiCommand.php
                          ^^ module_structure.unknown_directory: 'Console/' not in top-level allowlist
                          ^^ module_structure.command_wrong_location: *Command.php must live under Application/Console/Command/
```

```text
packages/semitexa-api/src/Demo/Application/Handler/CreateArticleHandler.php
                          ^^ module_structure.production_package_pollution: 'Demo/' is a forbidden name
                                inside a production package — local demo modules belong under src/modules/
```

```text
packages/semitexa-core/src/Application/Service/Sandbox/PocService.php
                                                ^^ module_structure.production_package_pollution: 'Sandbox/' is forbidden anywhere
                                                   inside a production package, even nested under a legit layer
```

### Valid demo locations

```text
src/modules/ApiDemo/Application/Handler/PayloadHandler/CreateArticleHandler.php                              ✓ (local sandbox)
src/modules/Playground/tests/RestApi/Fixtures/Application/Handler/CreateArticleHandler.php       ✓ (host-app test fixture)
```

---

## 9. Workflow

* **Before placing a file**: skim § 2 and § 5.
* **After placing a file**: run `bin/semitexa ai:verify --files=<path>`. The `module_structure` check runs automatically on every affected module.
* **On failure**: read the `violation` events, follow `doc_ref` and `suggested_fix`, move the file, re-run.
* **To change a rule**: edit `packages/semitexa-dev/config/module-structure.php` (the executable spec) and update § 2 + § 5 of this document to match. Run `bin/semitexa test:run` to confirm the spec change is internally consistent.

---

## 9.5. Local package module-structure extensions

Some Semitexa packages are themselves *framework primitives* — they define
abstractions every other package consumes. `semitexa-orm` is the canonical
example: it defines what an "adapter" is, what a "repository abstraction"
is, the query DSL, and so on. Those layers cannot live under the
consumer-side canonical structure (`Application/Db/<Adapter>/...`)
because the package *is* the thing that defines `<Adapter>`.

Rather than hardcode every framework-primitive directory in this global
spec (which would let agents assume those directories are valid
everywhere), Semitexa supports **package-local module-structure
extensions**.

### Mechanism

A framework package may ship two files:

| Path | Role |
|---|---|
| `packages/<pkg>/config/module-structure.php` | Executable local rules — returns a `LocalModuleStructureExtension`. |
| `packages/<pkg>/docs/MODULE_STRUCTURE.md` | Human explanation — companion to the executable rules. |

The executable file is the **only** source of validation truth. Markdown
alone never validates anything — otherwise an agent could "fix" a
violation by editing prose.

### Effective rules

For a package with a local extension:

```
effective_rules = global_module_structure_rules + local_extension
```

Local extensions are **strictly additive**:

- they may add a small, named set of additional top-level directories and
  root files specific to that one package;
- every authorised directory must have an explicit `pathRules` entry
  (silent skipping is forbidden — the same Phase 2 rule that applies to
  `semitexa-core`'s `packageSpecificCodeRoot`);
- top-level directories use one of the existing modes:
  `MODE_LEAF_FILES_ONLY`, `MODE_DEEP_VALIDATED`, or — only with
  documented owner / reason / todo — `MODE_OPAQUE_INTERNAL`.

### Hard guard rails (enforced by `ModuleStructureSpecLoader`)

A local extension **cannot**:

- declare a top-level directory whose name is in the global
  production-pollution deny-list (`Demo`, `Sandbox`, `Playground`,
  `Example`, `Sample`, `Fake`, `Experimental`, `TestApp`, ...);
- redeclare a canonical top-level layer (`Application`, `Domain`,
  `Configuration`, `Context`, `Update`, `Static`, `View`, `Exception`,
  `Attribute`, `Auth`, `Discovery`, `OpenApi`, `Pipeline`);
- introduce a rule for `Domain/Contract/`, `Exception/`, or `Attribute/`
  — the global `*Interface.php` / `*Exception.php` / singular
  `Attribute/` conventions are non-negotiable;
- declare a top-level file whose basename is not a `*.php` (non-PHP
  package metadata stays in the global `packageRoot` rule);
- apply to any other package or to `src/modules/*`.

Violating any guard rail makes the loader throw at boot — the package
fails to validate, not silently. Diagnostic codes:
`module_structure.local_extension_invalid` and
`module_structure.local_extension_forbidden_override`.

### Scope isolation

Per-package scoping is implemented via `ModuleStructureSpec::$packageScopedRules`
(map of `package_name → relative_path → rule`). When the validator looks
up a rule for path `X` inside package `P`, it consults
`$packageScopedRules[P][X]` first and only falls back to the global
`$codeRootRules[X]` if no scoped rule exists. This prevents a rule
contributed by package `A`'s local extension from leaking into package
`B`'s validation, even if both packages happened to declare the same
top-level directory name.

### Example: `semitexa-orm`

`semitexa-orm` declares (and the spec docs at § 5.6 already document the
canonical FQCNs for) these ORM-only top-level directories:

`Adapter/`, `Trait/`, `Repository/`, `Query/`, `Metadata/`, plus the
root file `OrmManager.php`.

See:

- [`packages/semitexa-orm/config/module-structure.php`](../../semitexa-orm/config/module-structure.php) — executable rules
- [`packages/semitexa-orm/docs/MODULE_STRUCTURE.md`](../../semitexa-orm/docs/MODULE_STRUCTURE.md) — human explanation

This local extension does **not** make `Adapter/`, `Query/`, `Repository/`,
etc. valid in `semitexa-api`, `semitexa-cache`, `semitexa-mail`, or any
other package — they continue to fail there with
`module_structure.unknown_directory`. Consumer packages still use
`Application/Db/<Adapter>/Model/` for `*Resource`, `*ResourceModel`,
`*Mapper` files and `Application/Db/<Adapter>/Repository/` for concrete
`*Repository` implementations. `Domain/Repository/` remains forbidden
everywhere.

### Querying which rules apply

`ai:ask --path=<path>` (or `ai:ask path --path=<path>`) explains a path
using both the global rules and any package-local extension. It reports:

- detected package / module,
- which docs were consulted,
- which executable rule files were consulted,
- whether the path is `allowed` / `invalid` / `unresolved` / `outside_module`,
- `rule_scope`: `global` / `local` / `none`,
- `exists`: whether the path actually exists on disk (allows hypothetical
  classification — e.g. a feature-grouped child that would be allowed if
  created),
- whether the path is part of the package's public API (`public_api`),
- a short `suggested_action` if the path is invalid,
- a `warnings[]` list — including a hard reminder when a path is allowed
  *only* because of a local extension, and a separate note when the rule
  was inherited from a feature-grouping ancestor.

`ai:ask --path` resolves rules with the **same feature-grouping
inheritance** as `ai:verify`: when a parent rule has
`allowFeatureGrouping: true` (e.g. `Application/Service`), every
descendant feature-group directory inherits the parent rule. So
`packages/<pkg>/src/Application/Service/Transaction/` reports `allowed`
with a warning that the rule was inherited from `Application/Service`,
not because `Transaction/` is a separately declared layer.

Use this whenever you are unsure whether a directory is canonical.

---

## 10. Non-goals

This document does **not** govern:

* Class naming or namespacing (see `AI_BEST_PRACTICES.md` § 4–§ 9 and the `lint:*` family).
* Twig template directory shape inside `templates/` (see `AI_BEST_PRACTICES.md` § 1).
* Theme directory shape (see `AI_BEST_PRACTICES.md` § 1 and the theme manifest spec).
* Test directory shape (`tests/Unit/`, `tests/Integration/`, fixtures).

These have their own conventions and are checked by their own tools.
