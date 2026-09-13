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
