---
id: migration/post-hardening
section: migration
slug: post-hardening
title: Post-Hardening Migration Guide
summary: Migrate from the legacy access model and webhook flow to Semitexa's current architecture — explicit access attributes, tenant-aware webhooks, atomic replay protection, and the unified quality gate.
order: 10
locale: en
status: canonical
keywords:
  - migration
  - "#[AsPublicPayload]"
  - "#[AsProtectedPayload]"
  - "#[AsServicePayload]"
  - "#[AsWebhookReceiver]"
  - markIfFirstSeen
  - webhook:cleanup
  - tenant-aware
  - bin/semitexa test:run
---
# Post-Hardening Migration Guide

This guide is the single canonical reference for upgrading an application from the legacy Semitexa surface to the current architecture. It covers payload access, auth, webhook receivers, persistence, lifecycle stores, generators, and the quality gate.

The "Before" examples in this document deliberately quote the retired shapes for context. They are forbidden everywhere else in durable docs and code.

## 1. Payload access — explicit attributes

The framework no longer ships a generic payload attribute. Access is explicit at the type level.

| Use case | Attribute |
|---|---|
| Anonymous endpoints (login, marketing, health) | `#[AsPublicPayload]` |
| Authenticated user endpoints (the safe default) | `#[AsProtectedPayload]` |
| Machine-to-machine endpoints (webhooks, partner APIs) | `#[AsServicePayload]` |

Each payload class must declare exactly one of the three. The access policy resolver reads the attribute at boot — there is no implicit default-protected fallback. Generators (`make:payload`, `make:page`) accept `--access=public|protected|service` and refuse unknown values.

### Before

```php
use Semitexa\Core\Attribute\AsPayload;
use Semitexa\Authorization\Attribute\PublicEndpoint;

#[AsPayload(path: '/api/profile', methods: ['GET'], responseWith: ProfileResource::class)]
class ProfilePayload {}

#[PublicEndpoint]
#[AsPayload(path: '/api/login', methods: ['POST'], responseWith: LoginResource::class)]
class LoginPayload {}
```

### After

```php
use Semitexa\Authorization\Attribute\AsProtectedPayload;use Semitexa\Core\Attribute\AsPublicPayload;

#[AsProtectedPayload(path: '/api/profile', methods: ['GET'], responseWith: ProfileResource::class)]
class ProfilePayload {}

#[AsPublicPayload(path: '/api/login', methods: ['POST'], responseWith: LoginResource::class)]
class LoginPayload {}
```

### Migration steps

1. Find every payload class:
   ```bash
   grep -rln "#\[AsPayload\|#\[PublicEndpoint" src packages
   ```
2. For each class, choose the access attribute by reading the route's intent — anonymous, authenticated, or service-domain.
3. Replace the attribute(s) and update the `use` statement to `Semitexa\Authorization\Attribute\As<Type>Payload`.
4. Run `bin/semitexa ai:verify --all`. The framework's regression suite blocks `#[AsPayload(` and `#[PublicEndpoint]` in new code.

## 2. Auth domain model

User-domain auth and service-domain auth are distinct in the runtime, not just in attribute names.

- **Protected payloads** require a User-domain principal (session, token, OAuth). A Service-domain credential never satisfies a protected route.
- **Service payloads** require a Service-domain principal (signed webhook, machine token, partner credential). A User-domain credential never satisfies a service route.

The boundary is enforced by `PreHydrationAuthGate` before the handler runs. Cross-domain attempts return 401 — no fallback, no silent escalation.

## 3. Permissions and capabilities

Two complementary RBAC mechanisms exist, both runtime-enforced.

### User-domain (protected payloads)

```php
#[AsProtectedPayload(path: '/api/admin/users', methods: ['GET'], responseWith: AdminUsersResource::class)]
#[RequiresPermission('users.manage')]
class AdminUsersPayload {}
```

- `#[RequiresPermission('users.manage')]` — fine-grained RBAC; checked against the authenticated user's permission set.
- `#[RequiresCapability('admin')]` — coarse-grained gate; checked against the user's capability set.

A missing permission/capability returns 403; an unauthenticated request returns 401.

### Service-domain (service payloads + webhooks)

Service payloads use `#[RequiresCapability]` only — service permissions are not modeled. Capabilities are resolved by the wired `ServiceCapabilityProviderInterface` implementation:

```php
public function getCapabilitiesForService(string $serviceId, ?string $tenantId = null): array;
```

The `$tenantId` argument is the resolved tenant for the current request. Multi-tenant production deployments MUST scope grants by tenant — a grant for service `partner-x` in tenant A must not authorize the same id in tenant B. Providers ignoring the parameter remain backward-compatible at the call-site but are unsafe in multi-tenant deployments.

The decision cache key composed by `SubjectGrantResolver::cacheKey($subjectType, $identifier, $tenantId)` is `{tenantId|'-'}:{subjectType}:{identifier}` — tenant id sits first so per-tenant invalidation can prefix-match cheaply, and untenanted entries (CLI tasks, single-tenant deployments) are encoded as a literal `-`.

## 4. Webhook receivers

Inbound webhook receivers are service payloads with an HMAC-SHA256 verification attribute. The retired pattern of marking signed webhooks as public endpoints is removed.

### Before

```php
#[PublicEndpoint]
#[AsPayload(path: '/webhooks/incoming', methods: ['POST'], responseWith: WebhookResource::class)]
class IncomingWebhookPayload {}
```

### After

```php
use Semitexa\Authorization\Attribute\AsServicePayload;
use Semitexa\Webhooks\Auth\Attribute\AsWebhookReceiver;

#[AsServicePayload(path: '/webhooks/incoming', methods: ['POST'], responseWith: WebhookAcceptedResource::class)]
#[AsWebhookReceiver(
    name: 'partner.event.signed',
    secretRef: 'env:PARTNER_WEBHOOK_SECRET',
    replayWindowSeconds: 300,
)]
class IncomingWebhookPayload {}
```

`WebhookAuthHandler` runs at auth-handler priority 5 (before all other handlers), reads the signing secret via the wired `WebhookSecretResolverInterface`, and verifies the HMAC against the request body and the `X-Webhook-Signature` header. The default `EnvWebhookSecretResolver` understands the `env:VAR_NAME` reference shape; production multi-tenant deployments wire a custom resolver to look up per-tenant secrets from a vault or secrets manager.

### Tenant-aware receiver identity

`WebhookAuthHandler` reads the active tenant via `TenantContextStoreInterface` (resolved by `TenancyPhase` before the auth phase) and stamps it on `WebhookPrincipal::$tenantId`. Downstream handlers and audit logs see the tenant id without re-querying the store. The principal's `getId()` stays the receiver name only — tenant is metadata, not identity.

## 5. Webhook replay protection

The seen-then-mark-seen flow had a check-then-act race: two concurrent coroutines could both observe `seen() === false` before either called `markSeen()`, and both proceeded to execute the once-only side effect. The atomic replacement is `markIfFirstSeen()`.

### Before

```php
if ($store->seen($key)) {
    throw new ConflictException('Duplicate event');
}
// race window — another coroutine may pass seen() here too
$store->markSeen($key);
```

### After

```php
use Semitexa\Webhooks\Application\Service\Auth\WebhookReplayKeyFactory;

$tenantId = $principal->tenantId;          // from WebhookPrincipal
$key = WebhookReplayKeyFactory::compose('partner.event.signed', $eventId, $tenantId);
if (!$store->markIfFirstSeen($key, $ttlSeconds)) {
    throw new ConflictException('Duplicate event');
}
```

`WebhookReplayKeyFactory::compose($namespace, $eventId, $tenantId)` produces `tenant:{id}:{namespace}:{eventId}` when a tenant is set, or the bare `{namespace}:{eventId}` shape for single-tenant / CLI usage. The same key shape works across all three replay store backings.

## 6. Webhook persistence backings

| Backing | When to use |
|---|---|
| `InMemoryWebhookReplayStore` (framework default) | Tests and demos. Per-process — does not span workers. |
| `RedisWebhookReplayStore` | Production with Redis. Atomic `SET NX EX`; server-side TTL. |
| `MySqlWebhookReplayStore` | Production with MySQL/MariaDB. Atomic `INSERT IGNORE` + PRIMARY KEY. Expired keys remain blocking until cleanup. |

The Redis and MySQL bindings are intentionally NOT auto-bound — production deployments wire whichever they choose explicitly in their container bootstrap. The choice is a deployment decision, not framework magic.

### Inbound dedupe

`InboundDeliveryRepositoryInterface::insertOrMatchDedupe()` is atomic via `INSERT IGNORE` + `UNIQUE (dedupe_key)`. The dedupe-key factory prefixes `tenant:{id}:` when a tenant is set, so two tenants with the same provider event id never collide on the unique constraint.

### Outbound publisher

`OutboundDeliveryRepositoryInterface::insertOrMatchIdempotency()` is atomic via `INSERT IGNORE` + `UNIQUE (endpoint_definition_id, idempotency_key)`. Per-tenant endpoint definition ids provide isolation when tenants register separate endpoints; shared endpoint definitions across tenants require the publisher to fold tenant id into `idempotency_key` itself.

### Outbound worker

`OutboundDeliveryRepositoryInterface::claimAndLease()` atomically claims a due delivery and bumps `attempt_count` in one statement. CAS finalization (`markDeliveredIfOwned`, `markRetryScheduledIfOwned`, `markFailedIfOwned`) only commits when the worker still owns the lease — stale workers that lost their lease cannot overwrite a row reclaimed by the new owner.

## 7. Webhook cleanup

The operator-facing cleanup driver lives at `bin/semitexa webhook:cleanup`.

```bash
# Preview what would be deleted (no rows touched)
bin/semitexa webhook:cleanup --dry-run

# Apply, with a per-call cap (rerun until backlog drains)
bin/semitexa webhook:cleanup --batch-size=1000

# Override the configured retention window
bin/semitexa webhook:cleanup --retention-days=14

# Tenant-scoped run (inbox + outbox + tenant-prefixed replay keys)
bin/semitexa webhook:cleanup --tenant=tenant-a --retention-days=30 --batch-size=500
```

Safety contract enforced in SQL, not just docs:
- **Outbox**: only `Delivered` / `Failed` / `Cancelled` are eligible. `Pending` / `RetryScheduled` / `Delivering` are preserved unconditionally. Rows with an unexpired lease are also preserved.
- **Inbox**: only `Processed` / `Failed` / `RejectedSignature` / `DuplicateIgnored` are eligible. `Received` / `Verified` / `Processing` are preserved.
- **Replay keys**: only rows with `expires_at IS NOT NULL AND expires_at <= NOW`. NULL or future expiry is preserved.
- **Tenant-scoped runs** skip `webhook_attempts` (audit log; no `tenant_id` column). Operators run a separate global pass for attempts.

Representative cron line:

```
17 * * * * /var/app/bin/semitexa webhook:cleanup --batch-size=5000 >>/var/log/webhook-cleanup.log 2>&1
```

## 8. Lifecycle and Swoole runtime

State falls into four classes; cleanup behaviour follows the class.

| Class | Examples | Reset |
|---|---|---|
| Per-request | `CurrentRequestStore`, `AuthContextStore`, `TenantContextStore`, `RbacDecisionCache`, `LocaleContextStore` (re-init), `requestScopedContainer` | After every `Application::handleRequest` and `QueueWorker::processPayload`, via `PerRequestStateRegistry::resetAll()` |
| Per-worker | `AttributeDiscovery`, `ClassDiscovery`, route registry, immutable caches | Worker boot only |
| Persistent / shared | DB rows, Redis state, queue state, event ledger | External — survives worker restarts |
| Test-only | `WebhookDemoEventStore`, `InMemoryWebhookReplayStore` (in tests) | `TestStateResetRegistry::resetAllForTesting()` |

In Swoole HTTP mode, every per-request store is per-coroutine isolated via `Coroutine::getContext()`. In CLI / queue / test mode, the same APIs fall back to a static field and the framework's per-request reset wipes them between executions. Putting tenant-aware grants behind `RbacDecisionCache::cacheKey()` includes the tenant id so cross-tenant cache bleed is prevented even within a single coroutine that switches tenants.

What must NOT be cleared per request:
- Discovery caches, registry resolvers, immutable configuration objects.
- Connection pools (DB, Redis, NATS).
- Long-lived in-memory replay stores in single-process deployments.

## 9. Generators

Use `bin/semitexa make:*` for new code. The generators encode the current attribute conventions and module structure.

```bash
bin/semitexa make:page --module=Catalog --name=ProductIndex --path=/catalog --method=GET --access=public
bin/semitexa make:payload --module=Webhooks --name=PartnerEvent --path=/webhooks/partner --method=POST --access=service
bin/semitexa make:handler --module=Catalog --name=ProductIndex --payload=ProductIndex --resource=ProductIndex
bin/semitexa make:module --name=Catalog --target=custom
```

`--access=public|protected|service` is the explicit access flag for `make:payload` and `make:page`. The default is `protected`. Invalid values fail the generator with a clear error before any file is written. Generated output uses the same access attribute conventions documented in §1.

## 10. Quality gate

Use the framework's containerized test runner and unified verification command. Direct host-side `vendor/bin/phpunit` is not supported — the host typically does not have the right PHP / Swoole / extension surface, and required services are wired only via the test compose overlay.

```bash
# Run the suite (containerized)
bin/semitexa test:run

# Targeted path
bin/semitexa test:run packages/semitexa-webhooks/tests

# Filter by name (note the single trailing -- before phpunit args)
bin/semitexa test:run -- --filter SomeTest

# Unified verification — lint + module structure + DI + tests
bin/semitexa ai:verify --all
```

`ai:verify --all` is the unified gate: it runs handler validity checks, DI lint, response-construction lint, template lint, and the module-structure validator across every package and module. Any drift fails before merge.

## 11. Migration checklist

Run these in order on the upgrading project:

1. `grep -rln "#\[AsPayload\|#\[PublicEndpoint" src packages` — confirm there is nothing else to update.
2. `bin/semitexa ai:verify --all` — green baseline before any changes.
3. Update payload attributes per §1. Verify `bin/semitexa test:run packages/semitexa-core/tests` and the affected module's tests after each module.
4. If you have webhook receivers, follow §4 and §5: switch to `#[AsServicePayload]` + `#[AsWebhookReceiver]`, replace `seen()`/`markSeen()` with `markIfFirstSeen()` and `WebhookReplayKeyFactory::compose(...)`.
5. If you wire a `ServiceCapabilityProviderInterface`, accept the optional `?string $tenantId` argument per §3 — even if you initially ignore it, the signature change is mandatory.
6. Schedule `bin/semitexa webhook:cleanup` per §7.
7. Run `bin/semitexa ai:verify --all` and `bin/semitexa test:run`. Both must be green.

## See also

- [Scaffolding Generators](../cli/scaffolding-generators.md) — `make:*` command reference and access-attribute mapping.
- [Runtime State Lifecycle](../runtime/state-lifecycle.md) — the four state classes, request lifecycle, tenant-aware webhook section, cleanup operational notes.
- [Protected Route](../auth/protected.md) — the authorization model.
