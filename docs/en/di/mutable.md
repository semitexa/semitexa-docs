---
id: di/mutable
section: di
slug: mutable
title: Mutable Injection
summary: "#[ExecutionScoped] opts a class into a per-execution clone; #[InjectAsMutable] marks the properties re-injected on that clone."
order: 40
locale: en
status: canonical
verified_against: 2026.11.21.1434
keywords:
  - InjectAsMutable
  - ExecutionScoped
  - execution-scoped
  - Request
---
# Mutable Injection

Two attributes work together here, and mixing them up is the usual mistake:

- **`#[ExecutionScoped]`** goes on the **class**. It says: build a prototype at boot, then clone it for every execution.
- **`#[InjectAsMutable]`** goes on a **property**. It says: this dependency is re-injected on each clone, so it is safe to hold per-execution state.

```php
#[AsService]
#[ExecutionScoped]
final class IriBuilder
{
    #[InjectAsReadonly]
    protected ResourceMetadataRegistry $registry;

    #[InjectAsMutable]
    protected Request $request;
}
```

That is `Semitexa\Core\Resource\IriBuilder`, and it shows both tiers in one class: the metadata registry is worker-shared because it has no per-execution state, while the request is re-injected on every clone.

The `#[AsService]` is not redundant here. `#[ExecutionScoped]` sets the *tier*; it does not make a class container-managed on its own. `#[AsService]` is what puts the class under the container in the first place — see [declaring a service](services.md).

## An execution is a request, a console run, or an async job

"Execution scope" is not "request scope". The same boundary applies to a console command run and to an async job, which is what makes it the right unit in a long-running worker.

## Most classes never write `#[ExecutionScoped]`

It is **implied** by the five attributes that already mark per-execution work:

- `#[AsPayloadHandler]`
- `#[AsEventListener]`
- `#[AsPipelineListener]`
- `#[AsAuthHandler]`, from `semitexa/auth`
- `#[AsSlotHandler]`, from `semitexa/ssr`

So a handler is already execution-scoped and simply uses `#[InjectAsMutable]` for the things that change per request:

```php
#[AsPayloadHandler(payload: ..., resource: ...)]
final class DefaultNotFoundPageHandler
{
    #[InjectAsMutable]
    protected Request $request;
}
```

Write `#[ExecutionScoped]` explicitly only for a class that needs per-execution state without being a handler or listener — `IriBuilder` above is exactly that case.

## What goes wrong without it

A worker-scoped service that quietly accumulates per-request state is the classic long-running-runtime bug: the second request sees the first request's data, and only under load.

**Nothing checks this today.** No tool looks at what a worker-shared service stores. A property that holds request data passes `lint:di` and PHPStan when it is private or public, or protected and typed as a scalar, an array or a concrete class: `private ?string $tenantId` is the usual shape, and it passes.

One shape is reported, for a different reason. A protected property with no injection attribute, typed as `Request` or as any type whose name ends in `Interface`, is flagged by `UnannotatedServicePropertyRule` as a missing injection attribute.

The service has to be written correctly: either the class is `#[ExecutionScoped]`, or it reads the context at call time from an injected store:

```php
#[AsService]
final class TenantLabel
{
    #[InjectAsReadonly]
    protected TenantContextStoreInterface $tenantStore;

    public function current(): ?TenantContextInterface
    {
        // read on every call: the store keeps the tenant per coroutine
        return $this->tenantStore->tryGet();
    }
}
```

What the tools do catch is narrower. `ExecutionScopedWithoutAttributeRule` and `bin/semitexa lint:scoping` flag `#[InjectAsMutable]` on a service, contract implementation or repository that carries no scope attribute. Without one the class is built once per worker, so the property never receives per-execution values.

The inverse mistake is cheaper but still real: marking something execution-scoped that has no state at all just buys a clone per request for nothing. Shared is the default for a reason — see [readonly injection](readonly.md).
