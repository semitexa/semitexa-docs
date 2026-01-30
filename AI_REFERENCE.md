# Syntexa Framework - AI Assistant Reference Guide

> **Purpose:** Quick reference for AI assistants working with Syntexa Framework  
> **Last Updated:** 2024  
> **Status:** Living document - updated as framework evolves

## 🎯 Core Philosophy

**Syntexa is a Swoole-only, attribute-driven, modular PHP framework** with:
- ✅ **Stateless architecture** - No global state, Swoole-safe
- ✅ **Attribute-driven configuration** - Routes, entities, handlers via PHP 8 attributes
- ✅ **Modular design** - Modules can extend each other via traits
- ✅ **Code generation** - Wrappers auto-generated for cross-module extensions
- ✅ **DDD approach** - Clear separation: Domain, Infrastructure, Application layers

## 📁 Project Structure

```
syntexa/
├── packages/syntexa/          # Core framework modules
│   ├── core/                  # Core framework (routing, attributes, DI)
│   ├── core-frontend/         # Frontend support (Twig, layouts)
│   ├── orm/                   # ORM (PostgreSQL, stateless)
│   ├── user-domain/           # User domain models & services
│   ├── user-api/              # User API handlers & DTOs
│   └── user-frontend/         # User frontend (SSR templates)
├── packages/acme/             # Third-party modules (extensions)
├── src/
│   ├── modules/               # Generated wrappers (project-specific)
│   └── infrastructure/        # Generated storage wrappers
├── docs/                      # Framework docs: packages/syntexa/docs/
└── server.php                 # Swoole entry point
```

## 📚 Documentation Structure (Framework)

Framework documentation lives **per package** and in the shared docs folder:

```
packages/syntexa/
├── docs/                      # Shared framework docs (start here)
│   ├── README.md              # Index + package map
│   ├── AI_REFERENCE.md        # This file
│   ├── architecture/          # Architecture decisions
│   └── guides/                # Conventions, examples
├── core/docs/attributes/      # Attribute documentation
├── orm/docs/                  # ORM-specific docs
├── dev-tools/README.md        # Dev tools, AI endpoint
└── window-manager/README.md   # Window manager
```

**Key:** See `packages/syntexa/docs/README.md` for the full package map and install hints.

## 🏗️ Module Structure

### Standard Module Layout

```
module-name/
├── composer.json              # Must have "type": "syntexa-module"
├── src/
│   ├── Application/           # Application layer
│   │   ├── Handler/
│   │   │   └── Request/       # HTTP handlers
│   │   ├── Input/             # Request DTOs (no Http/ subfolder!)
│   │   ├── Output/            # Response DTOs
│   │   └── View/
│   │       └── templates/     # Twig templates
│   ├── Domain/                # Domain layer (business logic)
│   │   ├── Entity/            # Domain entities (no ORM attrs)
│   │   ├── Repository/        # Data repositories
│   │   └── Service/           # Business logic services
│   └── Infrastructure/        # Infrastructure layer
│       └── Database/          # Storage entities (ORM attrs)
```

### Module Types

1. **Domain Module** (`user-domain`) - Business logic, entities, repositories
2. **API Module** (`user-api`) - API handlers, Input/Output DTOs
3. **Frontend Module** (`user-frontend`) - SSR templates, frontend handlers

**Key:** Modules are split by concern, not by feature!

## 🔑 Key Concepts

### 1. Request/Response/Handler Pattern

**Flow:**
```
Request DTO → Handler → Response DTO → Template
```

**Example:**
```php
// 1. Request DTO
#[AsRequest(
    protocol: 'http',              // NEW: protocol field (default: 'http')
    path: '/dashboard',
    methods: ['GET'],
    responseWith: DashboardResponse::class
)]
class DashboardRequest implements RequestInterface {}

// 2. Response DTO
#[AsResponse(template: 'layout/dashboard.html.twig')]
class DashboardResponse implements ResponseInterface {}

// 3. Handler
#[AsRequestHandler(for: DashboardRequest::class)]
class DashboardHandler implements HttpHandlerInterface
{
    public function handle(RequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        // Business logic
        return $response;
    }
}
```

**Important:**
- Request path is in `Input/` folder (NOT `Input/Http/`)
- Protocol specified in `#[AsRequest(protocol: 'http')]` attribute
- Handler receives hydrated Request DTO and empty Response DTO

### 2. Entity Extension Pattern

**Two types of extensions:**

#### A. Storage Entity Extension (Infrastructure)

```php
// Base storage entity (in module)
namespace Syntexa\UserDomain\Infrastructure\Database;
#[AsEntity(table: 'users', domainClass: DomainUser::class)]
class User { }

// Extension trait (in another module)
namespace Acme\Marketing\Infrastructure\Database;
#[AsEntityPart(base: User::class)]
trait UserMarketingProfileTrait
{
    #[Column(name: 'marketing_tag', type: 'string', nullable: true)]
    public ?string $marketingTag;
}
```

#### B. Domain Model Extension (Domain)

```php
// Base domain model (in module)
namespace Syntexa\UserDomain\Domain\Entity;
class User { }

// Extension trait (in another module)
namespace Acme\Marketing\Domain\Entity;
#[AsDomainPart(base: User::class)]
trait UserMarketingProfileDomainTrait
{
    private bool $marketingOptIn = false;
    
    public function hasMarketingOptIn(): bool
    {
        return $this->marketingOptIn;
    }
}
```

**Generation:**
```bash
# Generates BOTH storage and domain wrappers
bin/syntexa entity:generate User
# or
bin/syntexa entity:generate --all

# Only domain wrappers
bin/syntexa domain:generate --all
```

**Generated files:**
- `src/infrastructure/Database/User.php` - Storage wrapper (base + storage traits)
- `src/modules/UserDomain/Domain/User.php` - Domain wrapper (base + domain traits)

### 3. Domain vs Infrastructure Separation

**CRITICAL:** EntityManager works ONLY with domain entities!

```php
// ✅ CORRECT - Use domain class
$user = $em->find(User::class, $id);  // User is domain entity

// ❌ WRONG - Storage entity cannot be used
$user = $em->find(StorageUser::class, $id);  // Throws exception!
```

**Repository pattern:**
```php
use Syntexa\Orm\Repository\DomainRepository;

class UserRepository extends DomainRepository
{
    public function __construct(
        #[Inject] EntityManager $em
    ) {
        // Pass domain class - repository resolves storage automatically
        parent::__construct($em, User::class);
    }
}
```

### 4. ORM - Direct Operations (No Unit of Work)

**Key difference from Doctrine:**
- ✅ `save()` writes immediately (no `flush()` needed)
- ✅ `update()` writes immediately
- ✅ `delete()` removes immediately
- ✅ Stateless - perfect for Swoole

```php
// Save immediately writes to database
$user = $repo->create();
$user->setEmail('test@example.com');
$saved = $repo->save($user);  // Written to DB immediately

// No flush() needed!
```

### 5. Code Generation Commands

```bash
# Request/Response wrappers
bin/syntexa request:generate --all
bin/syntexa response:generate --all

# Entity wrappers (storage + domain)
bin/syntexa entity:generate --all

# Domain wrappers only
bin/syntexa domain:generate --all

# Layouts
bin/syntexa layout:generate --all
```

**Generated wrappers location:**
- Requests: `src/modules/{Module}/Input/`
- Responses: `src/modules/{Module}/Output/`
- Storage entities: `src/infrastructure/Database/`
- Domain entities: `src/modules/{Module}/Domain/`

## 📝 Naming Conventions

### Classes
- **Request:** Must end with `Request` (e.g., `DashboardRequest`)
- **Response:** Must end with `Response` (e.g., `DashboardResponse`)
- **Handler:** Must end with `Handler` (e.g., `DashboardHandler`)
- **Entity:** Singular noun (e.g., `User`, `Order`)

### Files & Folders
- **Input DTOs:** `Application/Input/{Name}Request.php` (NO `Http/` subfolder!)
- **Output DTOs:** `Application/Output/{Name}Response.php`
- **Handlers:** `Application/Handler/Request/{Name}Handler.php`
- **Templates:** `Application/View/templates/{category}/{name}.html.twig`

### Attributes
- All attributes require `doc:` parameter pointing to documentation
- Use `env::VAR_NAME::default` for environment variables
- Protocol specified in `#[AsRequest(protocol: 'http')]`

## 🔧 Important Patterns

### 1. Module Extension via Traits

**Request extension:**
```php
#[AsRequestPart(base: LoginFormRequest::class)]
trait LoginFormRequiredFieldsTrait
{
    public ?string $email = null;
    public ?string $password = null;
}
```

**Entity extension:**
```php
#[AsEntityPart(base: User::class)]
trait UserMarketingProfileTrait { }

#[AsDomainPart(base: User::class)]
trait UserMarketingProfileDomainTrait { }
```

### 2. Environment Variables in Attributes

```php
#[AsRequest(
    path: 'env::API_LOGIN_PATH::/api/login',  // Double colon syntax
    methods: ['POST'],
    name: 'env::API_LOGIN_ROUTE_NAME::api.login'
)]
```

### 3. Request Inheritance

```php
// Base request
#[AsRequest(path: '/api', methods: ['GET'])]
class BaseApiRequest implements RequestInterface {}

// Derived request
#[AsRequest(
    base: BaseApiRequest::class,
    path: '/users'  // Overrides path, inherits methods
)]
class UserListRequest extends BaseApiRequest {}
```

### 4. Async Operations (Swoole)

```php
// Async save
$generator = $repo->saveAsync($user);
$saved = $generator->current();

// Parallel saves
$coroutines = [];
foreach ($users as $user) {
    $coroutines[] = Coroutine::create(function () use ($user, $repo) {
        yield from $repo->saveAsync($user);
    });
}
```

## 🚨 Common Pitfalls

### 1. ❌ Using Storage Entities Directly
```php
// WRONG
$user = $em->find(StorageUser::class, $id);

// CORRECT
$user = $em->find(User::class, $id);  // Domain entity
```

### 2. ❌ Forgetting to Generate Wrappers
```php
// After adding #[AsEntityPart] trait, must run:
bin/syntexa entity:generate --all
```

### 3. ❌ Wrong Folder Structure
```php
// WRONG
Application/Input/Http/DashboardRequest.php

// CORRECT
Application/Input/DashboardRequest.php
```

### 4. ❌ Missing Protocol in AsRequest
```php
// WRONG (defaults to 'http', but explicit is better)
#[AsRequest(path: '/dashboard')]

// CORRECT
#[AsRequest(protocol: 'http', path: '/dashboard')]
```

### 5. ❌ Using persist()/flush() Pattern
```php
// WRONG (Doctrine pattern)
$em->persist($user);
$em->flush();

// CORRECT (Syntexa pattern)
$em->save($user);  // Writes immediately
```

## 📚 Key Files to Know

### Core Framework
- `packages/syntexa/core/src/Application.php` - Main application class
- `packages/syntexa/core/src/Discovery/AttributeDiscovery.php` - Route discovery
- `packages/syntexa/core/src/Attributes/` - All framework attributes

### ORM
- `packages/syntexa/orm/src/Entity/EntityManager.php` - Entity operations
- `packages/syntexa/orm/src/Repository/DomainRepository.php` - Base repository
- `packages/syntexa/orm/src/CodeGen/` - Code generators

### Code Generation
- `packages/syntexa/core/src/CodeGen/RequestWrapperGenerator.php`
- `packages/syntexa/core/src/CodeGen/ResponseWrapperGenerator.php`
- `packages/syntexa/orm/src/CodeGen/EntityWrapperGenerator.php`
- `packages/syntexa/orm/src/CodeGen/DomainWrapperGenerator.php`

## 🎯 Quick Decision Tree

### "How do I create a new endpoint?"
1. Create `Input/{Name}Request.php` with `#[AsRequest]`
2. Create `Output/{Name}Response.php` with `#[AsResponse]`
3. Create `Handler/Request/{Name}Handler.php` with `#[AsRequestHandler]`
4. Run `bin/syntexa request:generate --all` (if using extensions)

### "How do I extend an entity?"
1. Create trait with `#[AsEntityPart]` or `#[AsDomainPart]`
2. Run `bin/syntexa entity:generate --all`
3. Use generated wrapper in `src/infrastructure/Database/` or `src/modules/`

### "How do I query data?"
1. Use `EntityManager` with domain class: `$em->find(User::class, $id)`
2. Or extend `DomainRepository` for custom queries
3. Remember: `save()` writes immediately (no flush)

### "Where do generated files go?"
- Requests: `src/modules/{Module}/Input/`
- Responses: `src/modules/{Module}/Output/`
- Storage: `src/infrastructure/Database/`
- Domain: `src/modules/{Module}/Domain/`

## 🔍 Attribute Reference Quick Lookup

| Attribute | Purpose | Required Params | Key Params |
|-----------|---------|----------------|------------|
| `#[AsRequest]` | HTTP Request DTO | `doc`, `path` | `protocol`, `methods`, `responseWith`, `base` |
| `#[AsResponse]` | HTTP Response DTO | `doc` | `template` |
| `#[AsRequestHandler]` | Request handler | `for` | `execution`, `priority` |
| `#[AsEntity]` | Database entity | `doc` | `table`, `domainClass` |
| `#[AsEntityPart]` | Storage extension | `base` | - |
| `#[AsDomainPart]` | Domain extension | `base` | - |
| `#[AsRequestPart]` | Request extension | `base` | - |
| `#[Column]` | Column mapping | `name` | `type`, `nullable`, `unique` |
| `#[Id]` | Primary key | - | - |
| `#[GeneratedValue]` | Auto-generated | - | - |

## 💡 Pro Tips for AI Assistants

1. **Always check for generated wrappers** - Don't edit files in `src/modules/` or `src/infrastructure/`
2. **Use domain classes** - Never use storage entities directly
3. **Generate after changes** - After adding traits, run generation commands
4. **Check protocol** - Remember `protocol: 'http'` in `#[AsRequest]`
5. **No Http/ folder** - Input DTOs go directly in `Input/`, not `Input/Http/`
6. **Read attribute docs** - All attributes have `doc:` parameter pointing to docs (in `packages/syntexa/core/docs/attributes/`)
7. **Stateless operations** - `save()` writes immediately, no flush needed
8. **Module structure** - Domain/API/Frontend split, not feature-based
9. **Package map** - See `packages/syntexa/docs/README.md` for available packages and install hints

---

**Remember:** Syntexa is Swoole-only, attribute-driven, and modular. When in doubt:
1. Check generated wrappers location
2. Verify domain vs infrastructure separation
3. Run generation commands after adding traits
4. Read attribute documentation via `doc:` parameter
