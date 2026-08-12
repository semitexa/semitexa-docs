---
id: runtime/request-context
section: runtime
slug: request-context
title: Request Context: Tenant, Auth, Locale
summary: Reaching the per-request tenant, auth and locale -- through the request-scoped container or statically -- and when each is still at its default.
order: 30
locale: en
status: canonical
keywords:
  - RequestContext
  - tenant
  - locale
  - request-scoped container
---

# Request Context: Tenant, Auth, Locale

Handlers and services get **tenant**, **auth**, and **locale** context from the request lifecycle. Context is **not** carried on the request/response DTO; it is available via **dependency injection** and **static access**.

## How to access context

### 1. Request-scoped container (recommended)

Inject the context interfaces into your handler or service. The container is request-scoped, so you receive the same instance for the current request (after tenant resolution, auth, and locale resolution have run).

```php
use Semitexa\Core\Tenant\TenantContextInterface;
use Semitexa\Core\Auth\AuthContextInterface;
use Semitexa\Core\Locale\LocaleContextInterface;

// In a handler or service resolved from the container:
$tenant = $container->get(TenantContextInterface::class);
$org = $tenant->getLayer(new \Semitexa\Core\Tenant\Layer\OrganizationLayer());

$auth = $container->get(AuthContextInterface::class);
$user = $auth->getUser();

$locale = $container->get(LocaleContextInterface::class);
$code = $locale->getLocale();
```

Use **protected/readonly properties** with `#[InjectAsReadonly]` (or your DI attribute) to inject these in handler classes.

### 2. Static access (when DI is not available)

- **Tenant:** `TenantContextInterface::get()` returns the current tenant context (or `null`). When tenancy is enabled, this is synced from the resolved context after `TenantResolverHandler` runs.
- **Auth:** `AuthManager::getInstance()` (semitexa-auth) provides the auth context after auth handlers run.
- **Locale:** `LocaleManager::getInstance()` (semitexa-locale) provides the locale after locale resolution (or after `TenantResolved` if using the locale layer).

Prefer **container injection** in handlers so dependencies are explicit and testable.

## Request lifecycle order

1. **Tenant** is resolved first (e.g. from header/path/subdomain); the result is stored and synced to the container and `TenantContext::set()`.
2. **Session and cookies** are initialized; **context interfaces** are set in the request-scoped container (tenant from step 1, default auth/locale).
3. **Locale** is resolved from the request (path/header) and the container is updated when the locale package is used.
4. **Route** is matched; request DTO (payload) is hydrated and validated.
5. **Auth** handlers run with the request payload; the container is updated with the auth context (e.g. `AuthManager`).
6. **Business handlers** run; they receive the same payload and resource DTO and can inject tenant/auth/locale from the container.

## When context is default

- **Tenant:** If tenancy is disabled or no tenant is resolved, the container and `TenantContextInterface::get()` provide a default context (e.g. single-tenant or “default” org).
- **Auth:** If auth is disabled or no handler sets a user, the container holds `GuestAuthContext`.
- **Locale:** If locale is not resolved, the container holds `DefaultLocaleContext` (e.g. `en`).

This design keeps the same handler signature regardless of which packages (tenancy, auth, locale) are enabled; context is always available via the same interfaces.
