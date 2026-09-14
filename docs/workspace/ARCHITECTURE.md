# Semitexa Architecture Overview

Semitexa is a high-performance PHP framework built on **Swoole**. It differs significantly from traditional PHP frameworks (stateless, request-per-process) by running as a long-lived application server.

## 🚀 Key Concepts

### 1. The Application Server (Swoole)
- **Stateful-ish**: The application boots **once**. Classes and services are initialized and kept in memory.
- **Request Loop**: Each HTTP request is handled by a lightweight coroutine or event loop iteration, not a fresh process.
- **Implication**:
  - **Performance**: Extremely fast (no bootstrap overhead per request).
  - **Memory**: Memory leaks are fatal. Static variables persist across requests (be careful!).
  - **Connection Pooling**: Database and Redis connections are persistent and reused.

### 2. Modular Design
Everything in Semitexa is a **Module**.
- **Discovery**: Modules are discovered via `composer.json` (`type: semitexa-module`).
- **Autoloading**: The framework uses an `IntelligentAutoloader` to map namespaces to paths dynamically.
- **No "App" Namespace**: The `src/` directory is just another location for modules. There is no monolithic `App\` namespace for business logic; logic lives in Domain Modules.

### 3. Request Lifecycle

1.  **Incoming Request (Swoole)**: Raw HTTP request is captured by Swoole.
2.  **Kernel**: The Request is passed to the Kernel.
3.  **Router**: Matches URL to a **Route Handler**.
    - Routes are defined by access attributes (`#[AsPublicPayload]` / `#[AsProtectedPayload]` / `#[AsServicePayload]`) on payload DTOs and `#[AsPayloadHandler]` on the matching handler classes inside each Module.
4.  **Hydration**: The raw request data is hydrated into a **Request DTO**.
    - Type safety is enforced here.
5.  **Validation**: The Request DTO validates itself. A payload implementing `ValidatablePayloadInterface` composes the rules it needs from the `Semitexa\Core\Validation\Trait\*` traits — `NotBlankValidationTrait`, `LengthValidationTrait`, `EmailValidationTrait`, `ConditionalValidationTrait` and the rest. The rules are traits the payload uses, not attributes on its properties.
6.  **Handler**: The **Handler** (Controller) executes the business logic.
    - Receives: `PayloadInterface $payload, ResourceInterface $resource`.
    - Returns: `ResourceInterface` (with data or view).
7.  **Response**: The Response DTO is rendered (JSON or HTML via Twig) and sent back to Swoole.

### 4. Service Contracts & DI
- **Interface-First**: Use interfaces for contracts.
- **Attributes**: `#[SatisfiesServiceContract(of: SomeInterface::class)]` is placed on **implementation** classes (in modules).
- **Injection**: Dependencies flow into container-managed classes via **protected** properties with `#[InjectAsReadonly]`, `#[InjectAsMutable]`, or `#[InjectAsFactory]` (**property injection is the One Way**; the constructor is never the DI channel). Request/Session/Cookie are injected by type into mutable clones.
  - *Constructors are still allowed*, just not as a DI mechanism. A parameterless `__construct` on a container-managed class is inert but tolerated; constructors are fully available on value objects, DTOs, payloads, resources, and other non-container-managed types. See **[DI_ONE_WAY.md](DI_ONE_WAY.md)** for the full rule and examples.
- **Resolution**:
  - If 1 implementation: Direct binding.
  - If >1 implementations: A **Resolver** can be generated in `src/registry/Contracts/` for contract resolution flows. For choosing by key, define a Factory* interface and inject it with `#[InjectAsFactory]`.

## 📂 Directory Structure

```
/
├── bin/              # CLI executables
├── packages/          # Monorepo packages — and two directories that are NOT packages, see below
├── public/           # Static assets (entry point for Nginx)
├── src/              # Application Source Code
│   └── modules/      # Domain Modules (User, Blog, Shop, etc.)
├── var/              # Temporary files (logs, cache)
├── vendor/           # Composer dependencies
└── server.php        # Application Entry Point
```

### Not everything under `packages/` is a Composer package

`packages/semitexa-*` is the glob that defines the package set — the capability
index is built from it, and so is the release. **Two directories sit inside that
glob and are not Composer packages at all.** They have no `composer.json`, they
are never tagged by the release flow, and they do not appear on Packagist. Each
ships by its own route:

| Directory | What it is | How it ships |
|---|---|---|
| `semitexa-installer` | A Docker project. `Dockerfile` + `entrypoint.sh` build a `php:8.4-cli-alpine` image that scaffolds a new project from an empty directory. | The image `semitexa/installer` on Docker Hub, pushed by the repository's own `docker-publish.yml` on a semver tag — nothing to do with the Composer release. |
| `semitexa-companion` | An MV3 browser extension (`manifest.json`, `content.js`, `rules.json`) that strips `X-Frame-Options` so Semitexa OS can embed external sites in its windows. | Loaded unpacked from `chrome://extensions`. |

This surprises people — and agents — every time, because a release reports
fewer packages than the number of directories it merged, which reads like
something was forgotten. Nothing was: the release enumerates
`packages/*/composer.json`, and these two directories have none. The count to
compare against is `ls -d packages/*/composer.json | wc -l`, never
`ls -d packages/semitexa-* | wc -l` — the two differ by exactly these two.

`semitexa-installer` still matters to the Composer release **indirectly**: it
owns `scaffold/`, the source of truth for project skeleton files. Those reach
consumers through `bin/sync-scaffold.sh` → `semitexa-ultimate` → Packagist, so a
scaffold change is released as part of `semitexa/ultimate`, not as itself.

### Which way a package may depend

Nothing declared cross-package dependency **direction** before, and 184
`semitexa/*` requirements exist across 42 packages. The policy below is derived
from decisions already made rather than invented, and each shape names the case
that produced it.

**1. Lifecycle packages are depended upon; they do not depend outward.**
`semitexa/update` owns `#[AsDataPatch]`, and `os`, `tasks` and
`platform-settings` hard-require `update` to use it. `semitexa/prompt` followed
the same shape for `#[AsUpdateAdvisory]`.

**2. Core declares the contract; packages satisfy it.** Core owns
`#[AsDoctorCheck]` and `DoctorCheckInterface`; `cache` and `orm` implement them
without core knowing either exists.

**3. Where the rule bites, the answer is a contract — not an edge.**
`RouteExecutor` lives in core and cannot see `#[ExternalApi]`, which lives in
`semitexa-api`. The resolution was `ExceptionResponseMapperInterface`: core
declares it, `semitexa-api` satisfies it, and nothing points outward. Reach for
this whenever the foundation appears to need something a feature package owns.

**The checks**, both in `PackageDependencyDirectionTest` (`semitexa-dev`):

- **No cycle, of any length.** A loop in the require graph means some package is
  *depended upon* and *depends outward* at once, which none of the three shapes
  allows. Detected depth-first, so `A → B → C → A` is caught as surely as a
  mutual pair. A ratchet: the list of known cycles may shrink, never grow, and a
  second test fails when an entry goes stale so the list cannot rot into a
  permission.
- **Direction, where the policy names a position.** A one-way `update → feature`
  edge breaks rule 1 and closes no loop, so cycles alone do not enforce the
  policy. `core` is pinned as the foundation — it requires nothing in the
  workspace — and `update` as lifecycle, allowed the foundation and persistence
  and nothing outward. Only those two, deliberately: classifying all 42 packages
  into layers would be inventing a map rather than recording decisions that
  exist.

**Four cycles exist today, and none of them is a legitimate exception.**
Measured 2026-09-14: every one is a composer requirement with **zero**
references of any kind — PHP, Twig, YAML, JSON or JS — in the direction that
creates it.

| Requirement | References backing it |
|---|---|
| `core` → `docs` | none |
| `core` → `tenancy` | none — the single mention is a docblock in `TenancyBootstrapperInterface`, whose purpose is to *avoid* the dependency |
| `cms` ↔ `os` | none, either way |
| `ssr` ↔ `theme` | none, either way |

Removing them is a deliberate change rather than a tidy-up, because dropping a
requirement changes what a consumer receives transitively.

## 🧩 The Module Anatomy

A typical module structure (see **the hub page `get-started/module-structure`** for the canonical source):

```
src/modules/MyFeature/
├── composer.json           # Module definition (type: semitexa-module)
├── Application/
│   ├── Payload/
│   │   ├── Request/        # payload DTOs (one of #[AsPublicPayload] / #[AsProtectedPayload] / #[AsServicePayload])
│   │   ├── Session/        # Session segment DTOs (#[SessionSegment])
│   │   └── Event/          # Event DTOs (dispatch)
│   ├── Resource/           # Response DTOs only (ResourceInterface)
│   ├── Db/                 # ORM models + repository implementations (optional)
│   ├── Handler/
│   │   ├── PayloadHandler/ # HTTP handlers (#[AsPayloadHandler])
│   │   ├── System/         # Pipeline/system listeners
│   │   └── DomainListener/ # Domain event listeners (#[AsEventListener])
│   ├── View/templates/     # Twig templates
│   └── Service/            # Optional module services
├── Domain/
│   ├── Model/              # Domain entities
│   └── Repository/         # Repository interfaces
```
