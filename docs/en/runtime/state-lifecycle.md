---
id: runtime/state-lifecycle
section: runtime
slug: state-lifecycle
title: Runtime State Lifecycle
summary: Per-request, per-worker, persistent, and test-only state — the Swoole-aware taxonomy that decides which caches reset between requests, which survive across requests, and which only reset when a test explicitly asks.
order: 10
locale: en
status: published
keywords:
  - PerRequestStateRegistry
  - TestStateResetRegistry
  - CurrentRequestStore
  - RbacDecisionCache
  - AuthContextStore
  - InMemoryWebhookReplayStore
  - Swoole
  - per-worker state
---
# Runtime State Lifecycle

Semitexa runs as a long-lived Swoole worker process: the container is built once at boot and serves N requests before the worker is restarted. That model is fast — but it means any module-level static state lives across requests by default, and getting the lifecycle wrong is a class of bug that looks fine in unit tests and breaks in production once a worker has been alive for a few minutes.

This page is the definitive taxonomy. It explains, with framework-class examples, which kinds of state must reset every request, which must survive every request, and which only reset on demand from tests or admin tools.

## The four state classes

Every mutable static store in the framework falls into exactly one of these classes.

### 1. Per-request state — must reset after every request and queued message

Holds data that is *about* the current request — the active HTTP `Request`, the authenticated principal, decisions cached for the current user. Carrying any of this into the next request is a security bug (one user's principal answering another user's question) or a correctness bug (a stale request reused after the connection closed).

Members of this class self-register a `clear()` callback with `Semitexa\Core\Lifecycle\PerRequestStateRegistry`. The framework calls `PerRequestStateRegistry::resetAll()` in the `finally` block of `Application::handleRequest` and `QueueWorker::processPayload`, so the reset happens on every code path — including 4xx, 5xx, and uncaught exceptions.

| Class | Registry name | Notes |
|---|---|---|
| `Semitexa\Core\Lifecycle\CurrentRequestStore` | `current_http_request_store` | Active `Request`, set by `PreHydrationAuthGate` so AuthHandlers can read headers before payload hydration. |
| `Semitexa\Rbac\Application\Service\RbacDecisionCache` | `rbac_decision_cache` | Resolved per-user grant set. Coroutine-local in Swoole, static fallback in CLI. |
| `Semitexa\Auth\Context\AuthContextStore` | `auth_context_store` | Authenticated principal + `AuthResult`. Coroutine-local in Swoole, static fallback in CLI/queue/tests. |
| `Semitexa\Tenancy\Context\TenantContextStore` | `tenant_context_store` | Active `TenantContext`. Coroutine-local in Swoole, static fallback in CLI/queue. Both `set()` and `setFallback()` are per-execution APIs; the framework wipes after every request and queued message. |

Adding to this class is a deliberate choice: the new store must be unsafe to carry across requests *and* must already have a cheap `clear()` operation. Self-register lazily on the first `set()` call so workers that never touch the store pay zero overhead.

### 2. Per-worker state — must survive across requests within a worker

Holds data that is *about the worker process*, not about a single request. Replay/idempotency sets, immutable metadata caches, connection pools, route discovery results. Resetting any of this between requests would either break correctness (replay protection clears, second copy of an event executes twice) or destroy Semitexa's "build once, serve N" performance model (re-discovering routes on every request).

Members of this class do *not* register with `PerRequestStateRegistry`. They live in module-level `static` properties that survive for the worker's lifetime.

| Class | Why it must survive |
|---|---|
| `Semitexa\Webhooks\Auth\InMemoryWebhookReplayStore` | Replay detection is meaningless if the seen-set clears every request. The whole point is "have I seen this `event_id` already in my lifetime as a worker?" |
| `Semitexa\Core\Discovery\AttributeDiscovery` | Route table is derived from the codebase; never changes mid-process. |
| `Semitexa\Core\Discovery\ClassDiscovery` | Composer classmap. Worker reads it once at boot. |
| `Semitexa\Core\Pipeline\HandlerReflectionCache` | Per-handler reflection. Re-deriving on every request would dominate the request budget. |
| `Semitexa\Authorization\Application\Service\PayloadAccessPolicyResolver` | Per-class access metadata. Immutable for the lifetime of the loaded class. |

In tests these stores still need a deterministic empty starting point — see Class 4 (test-only resettable) for how that's wired.

### 3. Persistent / shared state — survives across workers and process restarts

Lives in a database, Redis, queue broker, or other external system. Survives worker restarts, deploys, and full process replacement. Examples: `MachineCredential` repository, the production webhook replay store (DB-backed), the outbound delivery repository, the queue itself, the event ledger.

The framework holds no opinion on the lifecycle of these stores — they're owned by their backing service. Tests reset them via transaction rollback or schema teardown, not in-process state clearing.

### 4. Test-only resettable state — never reset by the framework, reset on demand

A subset of Class 2 (per-worker state) plus the demo grant stores. These survive across requests in production (because Class 2 says they must), but tests need a known-empty starting point between independent test runs.

Members of this class self-register with `Semitexa\Core\Lifecycle\TestStateResetRegistry`. Tests call `TestStateResetRegistry::resetAllForTesting()` in `setUp` / `tearDown`. The framework **never** calls this registry from the request lifecycle.

| Class | Why test-resettable, not per-request |
|---|---|
| `Semitexa\Webhooks\Auth\InMemoryWebhookReplayStore` | Per-worker by design (Class 2); tests use the shared registry to get one-call cleanup alongside demo stores. |
| `Semitexa\Modules\AuthDemo\Application\Service\AuthDemoPermissionStore` | Demo grant store — production grants come from a real repository. Tests seed/clear per scenario. |
| `Semitexa\Modules\AuthDemo\Application\Service\AuthDemoCapabilityStore` | Same pattern, capability domain. |
| `Semitexa\Modules\WebhookDemo\Application\Service\WebhookDemoEventStore` | Side-effect ledger for idempotency tests. Surviving across requests is the *point*. |
| `Semitexa\Modules\WebhookDemo\Application\Service\WebhookDemoServiceCapabilityStore` | Service-domain capability seeds for webhook tests. |

The two registries are separate on purpose. A code search for `PerRequestStateRegistry::resetAll` should never find a Class-2 store, and a search for `TestStateResetRegistry::resetAllForTesting` should never appear inside `Application` or `QueueWorker`. The naming makes accidental cross-wiring loud.

## What runs the per-request reset, and when

Two framework code paths run the per-request reset chain:

### `Semitexa\Core\Application::handleRequest`

Wraps the entire request pipeline (tenancy, session, locale, route execution) in a `try { … } finally { … }`. The finally block runs three resets in this exact order:

```php
} finally {
    PerRequestStateRegistry::resetAll();        // 1. registered per-request stores
    $this->requestScopedContainer->reset();     // 2. request-scoped container cache + ExecutionContext
    CoroutineLocal::endRequest();               // 3. coroutine-local CLI fallback
}
```

The order is intentional:

1. **`PerRequestStateRegistry::resetAll()` first** — registered callbacks (`RbacDecisionCache`, `CurrentRequestStore`, `AuthContextStore`, future ones) may still need to read request-scoped services during their cleanup. Running this first guarantees the request-scoped container is still alive when callbacks fire.
2. **`requestScopedContainer->reset()` second** — wipes the per-request cache (Request, Session, CookieJar, Auth/Tenant/Locale context) and tells the underlying `SemitexaContainer` to clear its `ExecutionContext` (per-coroutine in Swoole, CLI fallback in tests/queues). After this point no service can resolve a per-request binding.
3. **`CoroutineLocal::endRequest()` last** — generic cross-cutting CLI-fallback cleanup. May be touched by any of the above resets.

The whole chain runs even when:
- the handler throws an uncaught exception
- the route returns 4xx (unauthorized, validation failure)
- the route returns 5xx (mapped exception, internal error)
- the lifecycle short-circuits via an early response (tenancy redirect, locale redirect)
- a per-request reset callback throws (callbacks are individually `try/catch`-wrapped — see "Reset-callback failures are isolated" below)

### `Semitexa\Core\Queue\QueueWorker::processPayload`

Same `try { … } finally { PerRequestStateRegistry::resetAll(); CoroutineLocal::endRequest(); }` wrap, but the queue worker does NOT instantiate `Application` and does NOT use the request-scoped container — it processes messages through its own handler-resolution path. So `requestScopedContainer->reset()` is *not* part of the queue lifecycle by design. The per-message contract is "static stores reset, coroutine-local cleared" — sufficient because no Request/Session/CookieJar object exists in the queue path.

If you add a third entry-point (background task runner, scheduled job processor), match whichever of the two shapes applies — HTTP-style if it instantiates `Application`, queue-style if it doesn't. Add a regression test in `tests/Runtime/RequestScopedContainerLifecycleTest.php` (HTTP-style) or `tests/Runtime/StateLifecycleTest.php` (queue-style) that asserts the new entry-point clears the right registries.

## What runs the test-only reset, and when

`TestStateResetRegistry::resetAllForTesting()` is called only from:
- Test `setUp` / `tearDown` for any test that touches demo stores or the in-memory replay store.
- Future dev/admin commands that explicitly want to reset worker state (e.g. `bin/semitexa dev:reset-state`).

There is no production code path that calls it. The method name (`resetAllForTesting`, not `resetAll`) is part of the contract — a confused caller cannot mistake it for the per-request hook.

## Root container vs request-scoped container

Two distinct containers exist at runtime:

### Root container — `Semitexa\Core\Container\SemitexaContainer`

Built once per worker by `ContainerFactory::create()`. Holds:
- Framework services (router, discovery, attribute scanners, exception mapper).
- Module bindings (repositories, services, payload handlers as *prototypes*).
- Worker-scoped readonly singletons (e.g. `OrmManager`, `ConnectionRegistry`, `RedisConnectionPool`).

Execution-scoped classes — anything marked `#[ExecutionScoped]` or implied (`#[AsPayloadHandler]`, `#[AsEventListener]`, `#[AsPipelineListener]`, `#[AsAuthHandler]`) — live in the root container as *prototypes*. Each `get()` returns a fresh `clone` of the prototype with mutable properties injected from the current `ExecutionContext`. There is no per-request *instance* cache for these; the same prototype is cloned on demand.

**Never reset the root container per request.** It would defeat Swoole's "build once, serve N requests" model.

### Request-scoped container — `Semitexa\Core\Container\RequestScopedContainer`

Wraps the root container with a per-request cache. Holds the request-scoped *instances* that are unique to the current request:
- `Semitexa\Core\Request` (the active HTTP request)
- `Semitexa\Core\Session\SessionInterface`
- `Semitexa\Core\Cookie\CookieJarInterface`
- `Semitexa\Core\Tenant\TenantContextInterface`
- `Semitexa\Core\Auth\AuthContextInterface`
- `Semitexa\Core\Locale\LocaleContextInterface`
- Anything else a phase has explicitly `set()`-ed for the current request.

Setting these triggers `SemitexaContainer::setExecutionContext(...)`, which writes them into a coroutine-local store the cloned execution-scoped services read from at injection time.

`Application::handleRequest`'s finally block calls `requestScopedContainer->reset()` to wipe both the cache and the underlying `ExecutionContext`. In Swoole HTTP mode every request creates a new `Application()` (so a fresh `RequestScopedContainer`), but the same Application instance handles many requests in CLI / queue / test mode — without the explicit reset, the previous request's bindings would still be observable via `requestScopedContainer->get()`.

### What belongs in request scope

- The five framework context interfaces above and any per-request value derived from them.
- Anything that holds *current request identity* (request id, headers, current user/tenant/locale).

### What must never be request-scoped

- Connection pools (`RedisConnectionPool`, `OrmManager`) — re-acquiring a connection per request defeats pooling.
- Discovery, reflection, route, and access-policy caches — derived from the codebase, not request data.
- Replay/idempotency stores — must outlive a single request.
- Persistent repositories — owned by their backing service.

### How to test request-scoped services

Two approaches, both pin the same contract:

1. Dispatch through `Application::handleRequest` and observe the request-scoped container before and after. `$app->requestScopedContainer->has(Request::class)` returns `false` once `handleRequest` returns. See `tests/Runtime/RequestScopedContainerLifecycleTest.php` for the canonical patterns.
2. Add a tiny `#[ExecutionScoped]` probe service that records its instance id (`spl_object_id($this)`) on construction and exposes a getter. Resolve it from the request-scoped container in two sequential dispatches and assert the id differs — proves the framework returns a fresh clone per execution rather than reusing the previous request's instance.

## Swoole vs CLI: why this matters more than it looks

In Swoole HTTP mode, every request runs in its own coroutine. `Swoole\Coroutine::getContext()` is automatically destroyed when the coroutine ends, so any state stored via `CoroutineLocal` or `Coroutine::getContext()` directly is auto-cleaned with no framework involvement.

In CLI mode (PHPUnit, queue worker, console commands), there is no coroutine context. Class-1 stores fall through to a static fallback (`AuthContextStore::$fallbackUser`, `RbacDecisionCache::$staticFallback`, etc.). Without `PerRequestStateRegistry`, that fallback would persist across `Application::handleRequest` calls inside the same process — invisible in production Swoole, blatant in tests and queue workers.

`AuthContextStore` is the canonical example of why every Class-1 store needs explicit registration, not just coroutine-local storage: in CLI / queue / test mode the static fallback would persist across requests indefinitely without a `PerRequestStateRegistry::resetAll()` hook. The store self-registers on first set, so the framework wipes the fallback deterministically.

## Adding a new stateful store

Use this checklist:

1. **Pick a class** (1, 2, 3, or 4) by answering "what would happen if this state survived to the next request?"
2. **Class 1**: register with `PerRequestStateRegistry` lazily on first `set()`. Provide a cheap `clear()`.
3. **Class 2**: do nothing extra — module-level static state survives by default.
4. **Class 3**: persistence layer is responsible; framework has no hook.
5. **Class 4**: register with `TestStateResetRegistry` lazily on first write. Production code never calls the test registry.
6. **Add a lifecycle test** in `tests/Runtime/StateLifecycleTest.php` for the new store. Pin both directions: "must reset" or "must survive".

## Tenant context lifecycle

`Semitexa\Tenancy\Context\TenantContextStore` exposes three writers:

- `set(TenantContextInterface $context)` — primary per-request API, used by `TenancyPhase` after the resolver picks a tenant. Locks the context as immutable for the rest of the request when called inside a Swoole coroutine.
- `setFallback(TenantContextInterface $context)` — CLI / queue worker entry point, used by `TenantAwareJobSerializer::unwrapAndRestore()` to restore tenant identity for a queued job, and by `TenantRunCommand` via `swapFallback()` for the CLI `tenant:run` flow.
- `swapFallback(?TenantContextInterface $context)` — atomic swap returning the previous value; for tenant-switching dev tools that want to restore the previous tenant on exit.

Despite the naming, **all three are per-execution APIs**. There is no scenario in production code today where a tenant identity is meant to persist *past* the unit of work that established it. Three production call sites confirm this:

- `TenancyPhase` resolves the tenant per HTTP request and calls `set()`. The next request resolves again.
- `TenantAwareJobSerializer::unwrapAndRestore()` calls `setFallback()` per queued job. The next job calls it again with its own tenant.
- `TenantRunCommand` calls `set()` on entry and clears in its own `finally`. The framework wipe between any nested `Application::handleRequest` dispatches is harmless — the inner request would resolve its own tenant via the resolver chain.

`TenantContextStore` self-registers with `PerRequestStateRegistry` so the framework wipes both the coroutine-local context and the static fallback after every `Application::handleRequest` and `QueueWorker::processPayload`. Without this, tenant A's request could leave its `TenantContext` in the static fallback for a later anonymous request — a multi-tenant safety bug in CLI / queue / test mode where the same `Application` instance handles many requests.

In Swoole HTTP mode, `Coroutine::getContext()` already auto-cleans per request, so the leak surfaces only in CLI / queue / test environments. The per-request registration covers both axes.

## Locale context lifecycle

`Semitexa\Locale\Context\LocaleContextStore` is **not** registered with `PerRequestStateRegistry`. The framework's `LocalePhase` runs unconditionally on every dispatch and calls `LocaleBootstrapper::resolve()`, which always re-initializes `setLocale($defaultLocale)`, `setFallbackLocale(...)`, `setUrlPrefixEnabled(...)`, and `setDefaultLocale(...)` from configuration. Each request's locale is therefore overwritten before any handler runs — the previous request's locale cannot be observed.

This is correct but relies on `LocalePhase` always running. If a future entry-point bypasses `LocalePhase` (e.g. a custom queue path that calls `LocaleContextStore::setLocale('uk')` directly), the static fallback would persist across executions. If that pattern emerges, the fix is the same as `CurrentRequestStore` / `AuthContextStore` / `TenantContextStore`: lazy self-registration with `PerRequestStateRegistry`.

## Tenant-aware RBAC: cache key

`Semitexa\Rbac\Application\Service\RbacDecisionCache` keys are composed by `SubjectGrantResolver::cacheKey($subjectType, $identifier, $tenantId)`. The format is:

```
{tenantId|'-'}:{subjectType}:{identifier}
```

Tenant id sits first so a future per-tenant cache scan / invalidation can prefix-match cheaply. `null` tenant — CLI tasks without `tenant:run`, single-tenant deployments, system tasks — is encoded as a literal `-` so untenanted entries cannot accidentally compare equal to a legitimately tenanted entry whose id happens to be empty. Subject type sits second so a User principal `partner-x` and a Service principal `partner-x` continue to occupy distinct cache slots (cycle 10's collision fix).

The resolver reads the active tenant via `Semitexa\Core\Tenant\TenantContextStoreInterface::tryGet()` and the cross-package `OrganizationLayer` surface — no dependency on the concrete `Semitexa\Tenancy\Context\TenantContext`. That keeps semitexa-rbac decoupled from semitexa-tenancy.

The same tenant id is forwarded to `ServiceCapabilityProviderInterface::getCapabilitiesForService($serviceId, $tenantId)` so production providers can scope grants by tenant in the same call. Providers that ignore the parameter remain backward-compatible (the demo provider does so when registering grants via `setForService()`); providers that use it (the demo provider's `setForServiceInTenant()` path) prove cross-tenant isolation in tests like `TenantAwareWebhookPipelineTest::service_capability_grant_in_tenant_A_does_not_authorize_tenant_B`.

## Tenant-aware webhook pipeline

The webhook package holds tenant identity in three places, each with a distinct tenant-safety contract:

| Surface | Tenant scoping |
|---|---|
| `webhook_endpoint_definitions.tenant_id` | Column populated; lookup `findByEndpointKey()` is currently global. Operators must keep endpoint keys unique within their deployment OR register per-tenant lookup paths. Documented gap. |
| `webhook_inbox.tenant_id` / `webhook_outbox.tenant_id` | Column populated end-to-end from the resolved endpoint. `webhook:cleanup --tenant=<id>` filters deletes by this column. The unique constraints (`dedupe_key` for inbox; `(endpoint_definition_id, idempotency_key)` for outbox) inherit isolation from the dedupe-key composition (which prefixes tenant) and from per-tenant endpoint definition ids. |
| `webhook_replay_keys` | No `tenant_id` column. Tenant is folded into the key STRING via `WebhookReplayKeyFactory::compose($namespace, $eventId, $tenantId)` → `tenant:{id}:{namespace}:{eventId}`. Cross-backing safety: the same shape works for in-memory, Redis (`SET NX EX`), and MySQL (`INSERT IGNORE`) without backing-specific schema changes. |
| `webhook_attempts` | No `tenant_id` column. Audit log scoped by parent inbox/outbox id. `webhook:cleanup --tenant=<id>` skips attempts cleanup; operators run a separate global pass. |

### Tenant in webhook authentication

`WebhookAuthHandler` reads the active tenant via the framework's `TenantContextStoreInterface` (resolved by `TenancyPhase` BEFORE the auth phase runs) and stamps it on `WebhookPrincipal::$tenantId`. The principal's `getId()` stays the receiver name only — the subject identity is the receiver, not the tenant — but the tenant is now visible to:

- **Replay key construction**: `SignedWebhookHandler` composes its replay key via `WebhookReplayKeyFactory::compose(...)` with the principal's tenant id, so two tenants posting the same `X-Webhook-Event-Id` to the same receiver no longer collide on a shared replay store.
- **Secret resolution**: `WebhookSignatureVerifier` calls `WebhookSecretResolverInterface::resolve($secretRef, $tenantId)`. The default `EnvWebhookSecretResolver` is tenant-blind (env var → secret) for back-compat with single-tenant deployments and demo modules. Multi-tenant production deployments wire their own resolver to look up per-tenant secrets from a vault / secrets manager — no other code change required.
- **Audit logs / handler layers**: every consumer that reads the principal sees the tenant id without re-querying the store.

### Inbound dedupe key

`InboundDedupeKeyFactory::generate(...)` already prefixes its output with `tenant:{id}:` when a tenant id is provided. Two tenants posting the same provider event id to the same endpoint produce distinct `dedupe_key` strings, so the unique constraint on `webhook_inbox.dedupe_key` enforces per-tenant isolation without requiring `(tenant_id, dedupe_key)` to be in the index.

### Outbound idempotency

The unique constraint is `(endpoint_definition_id, idempotency_key)`. Two tenants registering distinct endpoint definitions (different UUID `endpoint_definition_id`) are isolated by construction. Two tenants sharing a single endpoint definition (intentional or accidental) are NOT isolated by the constraint — the same `idempotency_key` collapses into one delivery. Production deployments either:
- give each tenant its own `endpoint_definition_id` (recommended);
- fold tenant id into `idempotency_key` at the publisher;
- treat the shared endpoint as intentionally global.

### Worker claim and endpoint lookup — currently global, deferred

`OutboundDeliveryRepository::claimAndLease()` does NOT filter by tenant. One global worker pool drains all tenants' outboxes — delivery payloads carry their own `tenant_id` as metadata, but the worker's claim picks up the next due row regardless of tenant. Same for `WebhookEndpointDefinitionRepository::findByEndpointKey()` — it returns the first match. Both are intentionally deferred (cycle 24): per-tenant workers and per-tenant endpoint lookup require schema decisions (UNIQUE `(tenant_id, endpoint_key)` vs operator-managed uniqueness) that warrant their own focused cycle. `TenantAwareWebhookPipelineTest::worker_claim_and_endpoint_definition_lookup_remain_global_today` pins the current contract so a future change does not happen silently.

### `webhook:cleanup --tenant`

`bin/semitexa webhook:cleanup --tenant=<id>` restricts the per-table delete to rows whose `tenant_id` matches (inbox + outbox) or whose key carries the `tenant:{id}:` prefix (replay keys). `webhook_attempts` is global only — tenant-scoped runs surface a `— (skipped in tenant-scoped run)` cell for that table and emit a note prompting the operator to run a separate global pass. Dry-run mode runs the same filter via `SELECT COUNT(*)`.

### Recommended deployment models

The framework supports the same shared-DB tenant strategy across all webhook surfaces; per-tenant schema or per-tenant DB is also viable but lives at the deployment layer:

| Model | Replay store | Secrets | Cleanup | When to choose |
|---|---|---|---|---|
| Shared DB + tenant_id columns (default) | MySQL (folded prefix) or Redis (TTL) | Env vars or per-tenant resolver | `webhook:cleanup --tenant=<id>` | Single application instance serving multiple tenants. The audit + tests in cycle 24 cover this model. |
| Schema-per-tenant | Same as above per schema | Per-tenant via deployment routing | Per-schema cleanup runs | Strong tenant isolation requirement; comfortable with N schemas. Each schema owns its own webhook tables and the framework treats each as single-tenant. |
| DB-per-tenant | Per-tenant connection pool | Per-tenant via deployment routing | Per-tenant cron schedule | Maximum tenant isolation. The framework requires no changes — the connection pool is the boundary. |

### Operational caveats

- **Endpoint key uniqueness is operator-managed.** Until a `(tenant_id, endpoint_key)` UNIQUE index lands, two tenants registering `endpoint_key='stripe'` collide in `findByEndpointKey()` and the publisher / receiver will route to whichever was registered first.
- **Replay key cleanup cadence.** When tenant scoping folds into the key string, the `tenant:{id}:` prefix lives in the same `webhook_replay_keys` table as untenanted keys. `cleanupExpired()` without `--tenant` cleans both; with `--tenant=<id>` it only touches the prefixed rows. A deployment that mixes tenanted and untenanted writers must run BOTH a tenant-scoped pass per tenant AND a global pass for the legacy bare-key rows.
- **Service capability provider migration.** Existing custom providers that implement the old `getCapabilitiesForService(string $serviceId): array` signature continue to work — the new `?string $tenantId = null` parameter is additive. Providers ignoring the new arg ARE NOT tenant-safe in multi-tenant deployments; production deployments should audit and update them.

## Concurrent coroutines under Swoole

Swoole runs every HTTP request in its own coroutine inside a single worker process. Multiple coroutines run simultaneously — preempted only at I/O await points, never mid-statement. Every per-request lifecycle invariant rests on this single load-bearing assumption: **per-request state must be coroutine-aware**.

The concurrent-coroutine isolation tests run two HTTP requests in two real concurrent coroutines on the same worker and pin the contract end-to-end:

### Per-coroutine isolation primitives

| Store | Mechanism | Coroutine-isolated |
|---|---|---|
| `CurrentRequestStore` | `CoroutineLocal` → `Swoole\Coroutine::getContext()` per coroutine | yes |
| `AuthContextStore` | `Swoole\Coroutine::getContext()` per coroutine | yes |
| `TenantContextStore` | `CoroutineLocal::set()` per coroutine | yes |
| `RbacDecisionCache` | `Swoole\Coroutine::getContext()[KEY]` per coroutine | yes |
| `CoroutineLocal` | `Swoole\Coroutine::getContext()` per coroutine | yes |
| `SemitexaContainer::ExecutionContext` | `CoroutineLocal::set(EXECUTION_CONTEXT_KEY)` per coroutine | yes |

CLI / queue / test mode falls back to static fields *per store* — but each static is independently keyed (no cross-store contamination), and the framework's per-request reset wipes them after every `Application::handleRequest` and `QueueWorker::processPayload`.

### Cleanup callback isolation

The finally chain (`PerRequestStateRegistry::resetAll()` → `requestScopedContainer->reset()` → `CoroutineLocal::endRequest()`) runs **per coroutine**. Cleanup in coroutine A does NOT clear coroutine B's state:

- `PerRequestStateRegistry::resetAll()` invokes each registered callback. Every registered callback (`CurrentRequestStore::clear`, `RbacDecisionCache::clear`, `AuthContextStore::clear`, `TenantContextStore::clear`) reaches into `Swoole\Coroutine::getContext()` or `CoroutineLocal` — both of which target the *current* coroutine only. The callbacks DO null out static fallback fields that are shared across coroutines, but no other coroutine writes to those fields when running in coroutine mode, so the wipe is a no-op for everyone except the current coroutine.
- `requestScopedContainer->reset()` clears the `RequestScopedContainer` instance cache. **This is instance-shared** — meaning every Application instance has its own cache, but two coroutines using the *same* Application instance would share the cache. Production Swoole creates `new Application()` per request (see `SwooleBootstrap.php`), so the cache is naturally per-coroutine via instance separation. **Sharing one Application across concurrent coroutines is unsafe by design** — the framework documents the production model and pins it.
- `CoroutineLocal::endRequest()` clears only the current coroutine's `Swoole\Coroutine::getContext()` slot. Sibling coroutines keep their own context.

### RBAC cache coroutine isolation

`RbacDecisionCache` stores grants in `Swoole\Coroutine::getContext()[KEY][userId]`. Two coroutines authenticating the same `userId` with different tenant grants get independent cache entries — pinned by `rbac_decision_cache_is_coroutine_isolated` in `tests/Runtime/ConcurrentCoroutineIsolationTest.php`. The intra-request multi-tenant collision risk noted above is unchanged: if a single coroutine evaluates the same `userId` under two tenants in sequence within one request, the cache would still collide. Across coroutines there is no risk.

### Webhook replay/idempotency atomicity

Composing `seen()` and `markSeen()` as separate operations is a check-then-act race: two coroutines can both observe `seen() === false` before either calls `markSeen()`, and both proceed to execute the once-only side effect. `WebhookReplayStoreInterface::markIfFirstSeen()` exposes the atomic claim that production callers should use instead.

The atomic-claim API is `markIfFirstSeen(string $key, ?int $ttlSeconds = null): bool` — exactly one caller wins, optional TTL applied via the backing's native expiry mechanism.

In-tree implementations:

- **`InMemoryWebhookReplayStore`** (default for tests + single-worker demos): single PHP statement combining `isset()` check + assignment. Swoole coroutines do not preempt mid-statement, so the operation is atomic by construction. Honors TTL via lazy expiry — entries are marked with an absolute `expires_at` timestamp and treated as not-seen on the next touch after the deadline.
- **`RedisWebhookReplayStore`** (production for Redis-backed deployments): atomic via `SET key value NX EX <ttl>`. The Redis server guarantees one-winner semantics across all connected clients — multiple workers, multiple processes, multiple machines pointing at the same Redis DB. TTL is server-side; the key disappears automatically after the replay window.
- **`MySqlWebhookReplayStore`** (production for MySQL/MariaDB-backed deployments): atomic via `INSERT IGNORE INTO webhook_replay_keys (replay_key, first_seen_at, expires_at) VALUES (...)`. The PRIMARY KEY constraint on `replay_key` collapses check-and-claim into a single MySQL statement; affected-row count is the boolean answer. Atomicity holds across every PHP worker, every PHP process, every machine pointing at the same database. **Expired keys remain blocking until `cleanupExpired()` removes them** — a delete-then-insert policy would reintroduce a check-then-act race (two writers could both observe an expired row, both delete it, both insert their own — both win, both fire side effects). Operators schedule `cleanupExpired()` periodically (cron, queue job, admin command); the framework never relies on lazy expiry for correctness.
- **PostgreSQL-backed implementation** (recommended shape, not shipped): the same atomic-insert pattern with `INSERT ... ON CONFLICT DO NOTHING` and `affected_rows` inspection. `MySqlWebhookReplayStore` is the reference design.

`SignedWebhookHandler` reads `replayWindowSeconds` from `#[AsWebhookReceiver]` and passes it to `markIfFirstSeen()`. The framework does not issue `seen()` + `markSeen()` anywhere in production request-processing code; the contract docstring marks the two-call pattern as unsafe so it cannot be reintroduced silently.

#### Replay window / TTL behavior

| TTL passed | InMemoryWebhookReplayStore | RedisWebhookReplayStore | MySqlWebhookReplayStore |
|---|---|---|---|
| `null` | `PHP_INT_MAX` (forever for the worker's lifetime) | 1 year default — Redis without expiry would leak unbounded | NULL `expires_at` — row is permanent until manual deletion |
| positive int | absolute `expires_at = now() + ttl`; lazy expiry on next touch | server-side `EX ttl` | `expires_at = now() + ttl` written to row; expired rows still BLOCK until `cleanupExpired()` |
| `0` or negative | treated as immediate expiry | clamped to `1` second (Redis EX requires ≥ 1) | clamped to `0` seconds (`expires_at = first_seen_at`) — still blocks until cleanup |

#### Choosing a backing

| | InMemory | Redis | MySQL |
|---|---|---|---|
| Atomic across workers | no — single worker only | yes | yes |
| Atomic across processes | no | yes | yes |
| Atomic across machines | no | yes (same Redis) | yes (same DB) |
| Schema setup | none | none | one table |
| Native TTL | lazy (PHP-side) | server-side `EX` | none — operator runs `cleanupExpired()` |
| Delete-on-expiry race risk | none (single worker) | none (Redis owns expiry) | none (Option B blocks until cleanup) |
| Best for | tests, demos | high-throughput multi-worker production | DB-only deployments, audit-friendly |

#### Service binding

`InMemoryWebhookReplayStore` is the framework default — registered with `#[SatisfiesServiceContract]`. `RedisWebhookReplayStore` and `MySqlWebhookReplayStore` are intentionally NOT marked with the attribute; production deployments wire whichever they choose explicitly in their container bootstrap or a dedicated factory. Rationale: the choice of replay store (and which Redis / which database / which schema) is a deployment decision, not framework magic. Tests and demos use the in-memory default; production overrides explicitly.

#### Schema (MySQL)

`MySqlWebhookReplayStore` requires the `webhook_replay_keys` table. The ORM-managed shape lives at `packages/semitexa-webhooks/src/Application/Db/MySQL/Model/WebhookReplayKeyResourceModel.php`. Production deployments materialize it via the framework's schema-sync mechanism (`bin/semitexa orm:diff` to preview, `orm:sync` to apply). Equivalent raw SQL:

```sql
CREATE TABLE webhook_replay_keys (
    replay_key VARCHAR(191) NOT NULL,
    first_seen_at DATETIME NOT NULL,
    expires_at DATETIME NULL,
    PRIMARY KEY (replay_key),
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

The `replay_key` column is the natural primary key — there is no surrogate id. Atomicity of `markIfFirstSeen()` depends on the `PRIMARY KEY` constraint; any schema change must preserve uniqueness.

#### Webhook dedupe layers — defense in depth

The framework has THREE related-but-distinct dedupe layers in the webhook path:

1. **`WebhookReplayStoreInterface`** (inbound, request-level). Idempotency guard typically keyed by event-id with a TTL. Block duplicate request *processing* early at the receiver. Backings: in-memory (default), Redis, MySQL.
2. **`InboundDeliveryRepositoryInterface::insertOrMatchDedupe`** (inbound, persistent). Inbox audit dedupe with per-row lifecycle (`Received` → `Verified`/`RejectedSignature` → `Processing` → `Processed`/`Failed`/`DuplicateIgnored`). Records every inbound delivery for forensics, replay, and protects DB-backed inbox processing from double-execution.
3. **`OutboundDeliveryRepositoryInterface::insertOrMatchIdempotency`** (outbound, persistent). Publisher-side dedupe — two publishers with the same `(endpoint_definition_id, idempotency_key)` produce exactly one outbound delivery row, never two.

All three exist intentionally. They differ in what they protect:

| | Replay store | Inbound dedupe | Outbound idempotency |
|---|---|---|---|
| Direction | inbound | inbound | outbound |
| Scope | one event-id within the replay window | every inbound delivery, kept indefinitely | one published event per `(endpoint, idempotency_key)` |
| Backing | in-memory / Redis / DB | always DB | always DB |
| Side-effect blocked | the handler's once-only side effect | the inbound audit row itself | duplicate outbound delivery row + duplicate transport send |
| Identity | event-id | `dedupe_key` | `(endpoint_definition_id, idempotency_key)`, optional |
| Cleanup | TTL or `cleanupExpired()` | `deleteTerminalOlderThan()` retention | `deleteTerminalOlderThan()` retention |
| Failure mode if missing | duplicate side effect at the receiver | duplicate audit row + duplicate processing | duplicate outbound delivery + duplicate transport send |

Defense in depth is intentional: the replay store is fast and short-lived; the persistent dedupe layers are durable and audit-friendly. Don't remove one because the others exist.

#### Webhook pipeline concurrency model

The four atomic layers (publisher idempotency, worker claim/lease, worker CAS finalization, inbound replay/dedupe) are designed to compose. Under concurrent pressure they cooperate to deliver a strict end-to-end contract: **one logical webhook event produces exactly one transport send and exactly one downstream side effect.**

| Layer | Atomic primitive | Side effect blocked under concurrency |
|---|---|---|
| Publisher | `INSERT IGNORE` + `UNIQUE (endpoint_definition_id, idempotency_key)` | duplicate outbound delivery row |
| Worker claim | conditional `UPDATE … WHERE status IN (…) AND lease_expired` | duplicate claim of the same delivery |
| Worker CAS finalization | `UPDATE … WHERE status = 'delivering' AND lease_owner = :worker_id` | stale worker overwriting a row that another worker reclaimed |
| Inbound replay store | `markIfFirstSeen()` (in-memory: single PHP statement; Redis: `SET NX EX`; MySQL: `INSERT IGNORE` + `PRIMARY KEY`) | duplicate handler invocation at the receiver edge |
| Inbound dedupe | `INSERT IGNORE` + `UNIQUE (dedupe_key)` | duplicate inbox audit row + duplicate processing |

None of these layers replaces another. Each protects against a different failure mode:

- Without **publisher idempotency**, two app paths firing the same logical event create two outbound rows, and even perfect worker claim/lease will send two transport requests.
- Without **worker claim/lease**, two workers process the same outbound row and both call the transport.
- Without **CAS finalization**, a stale worker that finishes a long transport call after losing its lease overwrites the row written by the new owner — corrupting status, attempt count, and response data.
- Without the **replay store** at the receiver, a duplicate transport request (e.g. retry storm, multi-region fan-out) runs the receiver's once-only handler twice.
- Without **inbound dedupe**, the persistent inbox audit accumulates duplicate rows and the receiver's processing path runs twice for the same event.

#### Concurrency testing strategy

Deterministic concurrency tests run in their own PHP process via `#[RunTestsInSeparateProcesses]` + `#[PreserveGlobalState(false)]`. Each test:

1. Creates its own `webhook_outbox` (and `webhook_replay_keys` / `webhook_inbox` where relevant) tables in `setUp()`, drops them in `tearDown()`. No cross-test DB pollution.
2. Wakes the ORM's `MapperRegistry` on the main coroutine before any `Swoole\Coroutine\run()` block — the registry is lazily built and concurrent first-touches can race on initialization.
3. Coordinates coroutine release via `Swoole\Coroutine\Channel` start-gates and done-channels. No timing-dependent sleeps for synchronization.
4. Asserts concrete DB invariants: row counts by idempotency key, attempt counts, transport send counts, final statuses, lease ownership. Response status alone is not sufficient proof.

The capstone tests (`packages/semitexa-webhooks/tests/Integration/WebhookPipelineConcurrencyStressTest.php`) cover:
- N concurrent publishers with the same idempotency key → exactly one delivery row
- N concurrent publishers with different keys → exactly one delivery per key
- Same key on different endpoints → one delivery per endpoint
- N concurrent workers racing one pending delivery → exactly one transport send + one attempt count bump
- Sequential publisher-then-worker pipeline with concurrent burst → one delivery, one send, one attempt log
- Stale finalization rejection after forced lease expiry → row remains under the new owner
- Sustained burst across multiple distinct keys → one delivery + one send per key

#### Operational caveats

- **In-memory stores are per-process.** `InMemoryWebhookReplayStore` and the in-memory delivery fixtures provide atomic-claim semantics within ONE PHP worker process via Swoole's no-mid-statement-preemption guarantee, but they do NOT span workers. Multi-worker production deployments MUST use the MySQL- or Redis-backed implementations. The in-memory variants are for tests, demos, and single-process operation only.
- **The pipeline atomicity contract relies on the database UNIQUE constraints actually existing.** `orm:sync` materializes them automatically once the model attributes are loaded, but production deployments that have an existing `webhook_outbox` or `webhook_inbox` schema must add the indexes (and clean up any pre-existing duplicate rows) before upgrading the publisher / receiver code.
- **Concurrency happens on the database, not in PHP.** PHP coroutines cooperate; the load-bearing atomic operations happen in the SQL engine. A test that uses an in-memory fixture across simulated "workers" is testing PHP behavior, not production behavior. Stress tests must use the real MySQL repositories to mean anything.

#### Outbound publisher idempotency

Two concurrent publishers calling `WebhookPublisher::publish()` for the same logical event must not produce two outbound delivery rows. The publisher honors an OPTIONAL idempotency contract:

- **NULL idempotency key**: every publish creates a fresh delivery. MySQL UNIQUE indexes treat NULLs as distinct, so multiple NULL keys coexist by design. Use this when the producer doesn't care about duplicate publishes (or when the dedupe is enforced upstream).
- **Non-NULL idempotency key**: unique per `(endpoint_definition_id, idempotency_key)` via the `uniq_webhook_outbox_endpoint_idempotency` constraint on `webhook_outbox`. The first publish inserts; any subsequent publish with the same pair returns the existing row unchanged. Same idempotency key on a different endpoint is a separate delivery (producers can fan one logical event out to multiple endpoints with one shared key).

Atomicity comes from the database: `OutboundDeliveryRepositoryInterface::insertOrMatchIdempotency()` does a single INSERT and converts a duplicate-key violation into "matched existing" by re-fetching the row that won. A `findByIdempotencyKey() then insert()` sequence is forbidden — it's the same check-then-act race the inbound dedupe and replay store fixes already removed.

Callers can detect "matched existing" by comparing the returned delivery's id to the input id; a mismatch means the row already existed under another id. The publisher itself ignores the result — duplicate publishes silently no-op, which is the expected user-facing semantics.

**Recommended PostgreSQL pattern**: `INSERT ... ON CONFLICT (endpoint_definition_id, idempotency_key) DO NOTHING RETURNING *`, fall back to `SELECT` if `RETURNING` is empty (the conflict path).

**Migration caveat**: production deployments with an existing `webhook_outbox` table may have duplicate `(endpoint_definition_id, idempotency_key)` rows from before the constraint existed. `orm:sync` will fail to create the unique index until those duplicates are cleaned up. Sample cleanup query:
```sql
DELETE w1 FROM webhook_outbox w1
INNER JOIN webhook_outbox w2
  ON w1.endpoint_definition_id = w2.endpoint_definition_id
  AND w1.idempotency_key = w2.idempotency_key
  AND w1.idempotency_key IS NOT NULL
  AND w1.created_at > w2.created_at;
```
Inspect the duplicates before running — they may represent real data loss.

#### Outbound delivery claim-and-lease atomicity

The outbound delivery worker uses a claim-and-lease pattern so multiple workers can poll the same `webhook_outbox` table without sending the same delivery twice. The repository's `claimAndLease()` method does the entire claim — status flip, lease assignment, and attempt-count increment — in a single conditional UPDATE:

```sql
UPDATE webhook_outbox
SET lease_owner = :worker_id,
    lease_expires_at = :lease_expires_at,
    status = 'delivering',
    attempt_count = attempt_count + 1,
    last_attempt_at = NOW()
WHERE id = :id
  AND (
      (status IN ('pending','retry_scheduled')
       AND next_attempt_at <= NOW()
       AND (lease_expires_at IS NULL OR lease_expires_at < NOW()))
    OR
      (status = 'delivering'
       AND lease_expires_at IS NOT NULL
       AND lease_expires_at < NOW())
  )
```

Two workers competing on the same row only get one winner — the second one's UPDATE sees `status = 'delivering'` with a lease still owned by Worker A and the WHERE clause does not match, so `rowCount = 0` and the second worker treats it as "no work available". When Worker A's lease expires (because A crashed, hung, or partitioned), the same query allows another worker to reclaim — the `OR (status = 'delivering' AND lease_expired)` branch is the recovery path.

`attempt_count` is bumped IN THE SAME UPDATE so reclaims don't lose count. A PHP-side `$delivery->attemptCount++` after the claim would race: two workers reclaiming an expired lease in sequence would both read the same DB value and both write the same incremented value.

**Finalization is compare-and-swap.** After the transport call, the worker calls one of three CAS methods on the repository:

```sql
UPDATE webhook_outbox
SET status = 'delivered'/'retry_scheduled'/'failed',
    …response/retry/error fields…,
    lease_owner = NULL,
    lease_expires_at = NULL
WHERE id = :id
  AND status = 'delivering'
  AND lease_owner = :worker_id
```

If the lease has been reclaimed by another worker (because the original worker held the request too long), the WHERE doesn't match and the CAS returns `false`. The worker treats `false` as a **lost-lease outcome** — it logs the event, returns the outcome, and does NOT try to update the row again. The transport request was sent (the receiver's replay store dedupes it) but no DB clobber happens.

**The legacy "load delivery → mutate → save by id" pattern is forbidden** for status transitions. It would overwrite a row another worker already finalized. Use the CAS methods (`markDeliveredIfOwned`, `markRetryScheduledIfOwned`, `markFailedIfOwned`) for every state transition that follows a claim.

**Recommended PostgreSQL pattern:** the same SQL works with PostgreSQL with one tweak — `UPDATE … RETURNING *` to fetch the just-claimed row in one round trip. The conditional WHERE remains the atomic check. `SELECT … FOR UPDATE SKIP LOCKED` is an alternative for high-contention queues but the conditional UPDATE pattern above scales fine for typical webhook traffic.

#### Inbound delivery dedupe atomicity

A naive `findByDedupeKey()` then `insert()` sequence is the same check-then-act race shape as the webhook replay store. `InboundDeliveryRepository::insertOrMatchDedupe` avoids it the same way:

1. **Schema-level UNIQUE constraint** on `webhook_inbox.dedupe_key` (declared via `#[Index(columns: 'dedupe_key', unique: true, name: 'uniq_webhook_inbox_dedupe_key')]` on `WebhookInboxResourceModel`). The DB engine guarantees one-winner semantics for concurrent inserts.
2. **Try-insert + catch-duplicate** in the repository. The first writer's INSERT succeeds; the loser's INSERT raises a unique-constraint violation, caught and converted into the "matched existing" branch by re-fetching the row via `findByDedupeKey()` and calling `markDuplicateIgnored()`.
3. **Real DB errors are NOT swallowed**. Only an actual unique-constraint violation (SQLSTATE 23000 / MySQL error 1062, checked through the previous-exception chain) maps to a match. Table-missing, syntax errors, connection failures, permission errors all propagate so callers see a real failure instead of a silent "every webhook duplicated".

Production deployments that have an existing `webhook_inbox` table with no unique index on `dedupe_key` MUST add the index before upgrading. The schema-sync mechanism (`bin/semitexa orm:diff` to preview, `orm:sync` to apply) emits the necessary `CREATE UNIQUE INDEX` statement automatically once the framework model is loaded.

#### Multi-app deployments

If multiple applications share one MySQL database AND one `webhook_replay_keys` table, replay-key collisions across apps would corrupt each other's deduplication. Production deployments running multiple apps against shared infrastructure must either:
- give each app its own database (cleanest)
- give each app its own `webhook_replay_keys`-prefixed table
- agree on a key prefix convention (e.g. `app-name:webhook:demo:signed:<event-id>`) so the application namespace is encoded in the `replay_key` itself

The store does not enforce prefixing — that is a deployment decision.

#### Cross-worker implications

In-memory replay protection only works within ONE worker process. Two workers receiving the same retried webhook simultaneously would each have their own in-memory store and both could process the side effect. Production deployments that rely on once-only side effects (DB writes, outbound API calls, money movements) MUST use a shared backing — Redis or DB — so the atomic claim spans all workers.

The `RedisWebhookReplayStore` integration test (`packages/semitexa-webhooks/tests/Integration/RedisWebhookReplayStoreTest.php`) explicitly simulates this with two `RedisConnectionPool` instances pointing at the same Redis DB and pins exactly-one-winner across the two pools.

#### Persistence retention and cleanup

The webhook tables grow unbounded without operator intervention. Five surfaces accumulate rows:

| Surface | Backing | Cleanup mechanism |
|---|---|---|
| `webhook_replay_keys` | MySQL | `webhook:cleanup` (manual, runs `MySqlWebhookReplayStore::cleanupExpired()`) |
| Replay keys | Redis | server-side TTL — automatic, no cleanup work needed |
| Replay keys | in-memory | test-only — process exit drops the table |
| `webhook_inbox` | MySQL | `webhook:cleanup` (terminal statuses only) |
| `webhook_outbox` | MySQL | `webhook:cleanup` (terminal statuses only) |
| `webhook_attempts` | MySQL | `webhook:cleanup` (append-only audit log) |

The `bin/semitexa webhook:cleanup` command is the single operator entry point. It composes the per-table delete order, the dry-run preview, and the `--batch-size` cap into one invocation safe to schedule from cron.

```bash
# Preview what would be deleted (no rows touched)
bin/semitexa webhook:cleanup --dry-run

# Apply with a per-call cap (rerun until backlog drains)
bin/semitexa webhook:cleanup --batch-size=1000

# Override the configured retention window (defaults to WEBHOOK_RETENTION_DAYS / 30)
bin/semitexa webhook:cleanup --retention-days=14
```

A representative cron line for a once-an-hour cleanup:

```
17 * * * * /var/app/bin/semitexa webhook:cleanup --batch-size=5000 >>/var/log/webhook-cleanup.log 2>&1
```

##### Safety contract — what cleanup will never delete

Status-aware deletes are the load-bearing part of the contract. The retention window alone is not a sufficient filter — a row "older than the cutoff" may still represent live work.

- **Outbound (`webhook_outbox`)**: only `Delivered`, `Failed`, `Cancelled` rows are eligible. `Pending`, `RetryScheduled`, `Delivering` rows are PRESERVED unconditionally — they still represent live work, even if `created_at` is ancient. As defense-in-depth, any row carrying an unexpired `lease_expires_at` is also preserved so a worker mid-finalize cannot have its row pulled out from under it.
- **Inbound (`webhook_inbox`)**: only `Processed`, `Failed`, `RejectedSignature`, `DuplicateIgnored` rows are eligible. `Received`, `Verified`, `Processing` rows are PRESERVED — those are still in flight or queued for processing.
- **Replay keys (`webhook_replay_keys`)**: rows with `expires_at IS NULL` (intentionally permanent keys passed by callers with `$ttlSeconds = null`) are PRESERVED. Rows with `expires_at` in the future are PRESERVED. Only rows whose TTL has already passed are eligible.
- **Attempts (`webhook_attempts`)**: append-only audit log. Truncating cannot affect live work, so all rows older than the cutoff are eligible.

These filters live in the repository methods (`deleteTerminalOlderThan` for outbox/inbox, `cleanupExpired` for the replay store) so anyone bypassing the CLI command and calling the persistence layer directly inherits the same guarantees.

##### Dry-run and batch size

`--dry-run` runs the same status / cutoff / lease filter via `SELECT COUNT(*)` instead of `DELETE`, then emits the same summary table. No rows are mutated. Operators use this to size cleanup runs and confirm the filter is doing what they expect before applying.

`--batch-size=N` (default `1000`, `0` means unbounded) caps the per-table delete count via SQL `LIMIT N`. Large backlogs are drained by re-running the command — typically the same cron schedule that fires the cleanup also re-fires until the dry-run count converges to zero. The cap exists so a single delete transaction stays short, doesn't escalate into a row lock storm, and doesn't hold the InnoDB undo log open longer than necessary.

##### Output discipline

The summary surfaces row counts only — never payload bodies, header contents, secrets, or tenant-identifying material. Payload bodies live in `webhook_inbox.raw_body` and `webhook_outbox.payload_json`; the cleanup command never reads them. This is intentional so the cron output (typically captured to a log file with broad read access) cannot leak sensitive transport data.

##### Picking a backing for the replay store and what it implies for cleanup

| | In-memory | Redis | MySQL |
|---|---|---|---|
| Cleanup mechanism | none (process exit) | server-side TTL via `EX` | `webhook:cleanup` (cron) |
| Operator action required | none (test-only) | none | schedule the command |
| Expired keys still block until cleanup? | n/a | no — Redis evicts on TTL | yes — Option B blocks until cleanup runs |

The MySQL backing is the only one where cleanup cadence is operator-visible. The blocking-until-cleanup contract is intentional (it removes the delete-then-insert race), but it does mean an under-scheduled `webhook:cleanup` makes the replay window effectively longer than `replayWindowSeconds` advertises. A 24-hour replay window with daily cleanup is fine; a 5-minute replay window with daily cleanup is not — the window in practice is 24 hours.

### Swoole-specific anti-patterns

- **Sharing one `Application` instance across concurrent coroutines.** `RequestScopedContainer::$requestScopedCache` is instance-shared; two coroutines would race on the cache and the first cleanup would wipe the second's still-active state. Production Swoole creates a fresh `new Application()` per request via `SwooleBootstrap`. Custom code paths must follow the same pattern.
- **`seen()` then `markSeen()` for replay protection.** Use `markIfFirstSeen()` instead.
- **Storing `Request` / `AuthContext` / tenant / locale in static singleton fields without coroutine indirection.** Two coroutines would overwrite each other. Always go through `CoroutineLocal` or `Swoole\Coroutine::getContext()`.
- **Calling `Application::handleRequest` cleanup from outside the framework.** The finally chain assumes it runs on the same coroutine that established the per-request state. Calling it from a sibling coroutine wipes the wrong state.
- **PHPUnit tests that exercise Swoole runtime without `#[RunTestsInSeparateProcesses]`.** After `Swoole\Coroutine\run()` returns, shared resources (like `OrmManager`'s connection pool channels) can be left in a state that crashes subsequent non-coroutine tests with an uncatchable Swoole-level fatal. Forking a process per Swoole test isolates the runtime teardown — see `tests/Runtime/ConcurrentCoroutineIsolationTest.php` for the canonical pattern.
- **Calling Swoole `Channel` methods inside `__destruct`.** PHP shutdown tears down the Swoole runtime before destructors run; subsequent Channel method calls raise `Swoole\Error: must call constructor first` as a true PHP fatal that bypasses try/catch. Use a `register_shutdown_function` flag to skip Channel ops in the shutdown phase — see `Semitexa\Orm\Adapter\ConnectionPool` for the pattern.

## Common anti-patterns (and why they're bugs)

- **Storing `Request` in a singleton service property.** The next request will see the previous request's headers, body, query, and cookies. Inject `Request` via `#[InjectAsMutable]` on an `#[ExecutionScoped]` service instead — the framework injects the right one per execution.
- **Storing `AuthContextInterface` in a singleton service property.** Same shape — leaks the previous request's principal. Use `#[ExecutionScoped]` + `#[InjectAsMutable]`, or read from `AuthContextStore::getUser()` (which is per-request via the store's `PerRequestStateRegistry` registration).
- **Mutable singleton fields holding tenant / user / locale.** Same anti-pattern, different domain. Always use the framework context interfaces resolved per request.
- **Clearing metadata caches per request.** Defeats Semitexa's "build once" performance model. Metadata caches are derived from the codebase and don't change between requests.
- **Using static mutable state for request data.** Static stores survive across requests by default. If the data is per-request, register a `clear()` callback with `PerRequestStateRegistry`.
- **Calling `requestScopedContainer->reset()` from inside a handler.** The framework owns the lifecycle; resetting from inside a request would dispose the container while the current handler is still using it. Don't do this.
- **Skipping the per-request reset in a custom entry-point.** Any new entry-point that calls `Application::handleRequest` directly is fine — the reset is built into `handleRequest`. But a new entry-point that bypasses `Application` and calls handlers itself must run the same `try/finally` reset chain.
- **Reading `TenantContextStore::shared()->tryGet()` from a singleton-scoped service property.** The wrong tenant will be returned on the next request once the per-request reset wipes the store. Resolve the tenant per request — either inject `TenantContextInterface` into an `#[ExecutionScoped]` service (the framework injects the right one from `ExecutionContext`) or call `tryGet()` at request entry and pass the value through.
- **Calling `setFallback()` at boot time and expecting the tenant to persist across requests.** Despite the name, the fallback is per-execution. If you need a long-lived tenant identity for the entire worker (rare — mostly a misuse), use a different mechanism than `TenantContextStore`. The store is per-request by contract.
- **Adding tenant-keyed entries to `RbacDecisionCache` without adjusting the cache key.** The cache key is `userId` today. Two tenants' grants for the same `userId` would collide if the same request evaluated both. The per-request reset shields against cross-request leaks; intra-request multi-tenant resolution would need a tenant-aware key.

## Don't put these in PerRequestStateRegistry

- Route discovery cache (`AttributeDiscovery`, `RouteRegistry`) — re-discovery defeats Swoole's "build once, serve N" model.
- Reflection metadata (`HandlerReflectionCache`, `PayloadAccessPolicyResolver`) — derived from the codebase, never from request data.
- Class discovery (`ClassDiscovery`) — Composer classmap snapshot; immutable per-process.
- Replay/idempotency stores (`InMemoryWebhookReplayStore`) — clearing them defeats replay protection. Class 4: register with `TestStateResetRegistry` instead.
- Side-effect ledgers used for test assertions (`WebhookDemoEventStore`) — same answer.
- Demo grant stores (`AuthDemoPermissionStore`, `AuthDemoCapabilityStore`, `WebhookDemoServiceCapabilityStore`) — same answer.

## Reset-callback failures are isolated

Both registries invoke each callback inside a `try { $reset(); } catch (\Throwable) { }` block. A misbehaving cache cannot break the rest of the reset chain or, in `PerRequestStateRegistry`'s case, break the next request. The trade-off is that a permanently-broken reset callback leaves its cache "dirty" — strictly worse than the leak you were fixing in the happy path, but better than crashing every subsequent request handled by the same worker process.

If a reset callback can throw, fix the root cause in the cache itself rather than relying on the swallow.

## Diagnostics

Both registries expose `registeredNames(): list<string>` for diagnostics:

```php
PerRequestStateRegistry::registeredNames();
// → ['rbac_decision_cache', 'current_http_request_store', 'auth_context_store']

TestStateResetRegistry::registeredNames();
// → ['auth_demo_permission_store', 'auth_demo_capability_store',
//    'webhook_demo_event_store', 'webhook_demo_service_capability_store',
//    'in_memory_webhook_replay_store']
```

Use these in lifecycle assertions to prove the right kind of state is wired into the right registry. `tests/Runtime/StateLifecycleTest.php` does exactly this — including negative assertions that no replay or demo store accidentally appears in `PerRequestStateRegistry`.
