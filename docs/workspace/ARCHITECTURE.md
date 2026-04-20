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
    - Routes are defined via Attributes (`#[AsPayload]`, `#[AsPayloadHandler]`) in Modules.
4.  **Hydration**: The raw request data is hydrated into a **Request DTO**.
    - Type safety is enforced here.
5.  **Validation**: The Request DTO is validated (attributes like `#[NotBlank]`).
6.  **Handler**: The **Handler** (Controller) executes the business logic.
    - Receives: `PayloadInterface $payload, ResourceInterface $resource`.
    - Returns: `ResourceInterface` (with data or view).
7.  **Response**: The Response DTO is rendered (JSON or HTML via Twig) and sent back to Swoole.

### 4. Service Contracts & DI
- **Interface-First**: Use interfaces for contracts.
- **Attributes**: `#[AsServiceContract(of: SomeInterface::class)]` is placed on **implementation** classes (in modules).
- **Injection**: Dependencies flow into container-managed classes via **protected** properties with `#[InjectAsReadonly]`, `#[InjectAsMutable]`, or `#[InjectAsFactory]` (**property injection is the One Way**; the constructor is never the DI channel). Request/Session/Cookie are injected by type into mutable clones.
  - *Constructors are still allowed*, just not as a DI mechanism. A parameterless `__construct` on a container-managed class is inert but tolerated; constructors are fully available on value objects, DTOs, payloads, resources, and other non-container-managed types. See **[DI_ONE_WAY.md](DI_ONE_WAY.md)** for the full rule and examples.
- **Resolution**:
  - If 1 implementation: Direct binding.
  - If >1 implementations: A **Resolver** can be generated in `src/registry/Contracts/` for contract resolution flows. For choosing by key, define a Factory* interface and inject it with `#[InjectAsFactory]`.

## 📂 Directory Structure

```
/
├── bin/              # CLI executables
├── packages/          # Monorepo packages (Core, Frontend, etc.)
├── public/           # Static assets (entry point for Nginx)
├── src/              # Application Source Code
│   └── modules/      # Domain Modules (User, Blog, Shop, etc.)
├── var/              # Temporary files (logs, cache)
├── vendor/           # Composer dependencies
└── server.php        # Application Entry Point
```

## 🧩 The Module Anatomy

A typical module structure (see **packages/semitexa-core/docs/MODULE_STRUCTURE.md** for the canonical source):

```
src/modules/MyFeature/
├── composer.json           # Module definition (type: semitexa-module)
├── Application/
│   ├── Payload/
│   │   ├── Request/        # HTTP request DTOs (#[AsPayload])
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
