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

One shape of it is checked. The PHPStan rule `WorkerServiceCallerStateRule` (identifier `semitexa.workerServiceCallerState`) reports a property of a worker-shared container class when all of this holds:

- the property carries no `#[InjectAs*]` and no `#[Config]`;
- it is assigned through `$this`, directly or as an array element (`$this->p = ...`, `$this->p[] = ...`, `$this->p[$k] = ...`);
- the assigned expression reads a parameter of the enclosing method;
- the method is not `__construct`.

Up to semitexa/core 2026.11.21.1434 nothing checked this; the release after it adds the rule. It is registered in core's `config/phpstan-rules.neon`, so a project's `phpstan.neon` that includes that file runs it.

```php
#[AsService]
final class TenantLabel
{
    private ?string $tenantId = null;

    public function forTenant(string $tenantId): void
    {
        $this->tenantId = $tenantId; // reported: every request on the worker reads this value
    }
}
```

The declaration `private ?string $tenantId` is not what is reported: the rule reads the assignment. A property that the service fills from a context it reads itself still passes:

```php
private ?TenantContextInterface $tenant = null;

public function current(): ?TenantContextInterface
{
    // not reported, and just as wrong: the first request's tenant is kept for all the others
    return $this->tenant ??= $this->tenantStore->tryGet();
}
```

There are two fixes, and the report names both:

- **Request data: do not keep it.** Read it when the method runs, from the coroutine-local context (tenant, auth, locale) or from the method's own arguments. The example below does that.
- **A value that really is one per worker:** a registry filled at boot, a store wired at worker start, a test seam. Mark the property `#[WorkerState('why')]` (`Semitexa\Core\Attribute\WorkerState`), with the reason no request data can reach it. The rule then leaves the property alone.

```php
#[WorkerState('Channels keyed by name, registered once at worker boot; never from a request.')]
private array $channels = [];
```

### What the rule does not see

A clean PHPStan run is not proof that a service keeps no request data. The rule does not report:

- **a service that reads the context itself and keeps it**, as in `current()` above: no parameter is on the right-hand side;
- **a value copied to a local first:** `$v = $tenantId; $this->tenantId = $v;`. Values are not followed;
- **a method the service inherits** from a trait, or from a base class that is not itself container-managed;
- a parameter used only as the array key: `$this->seen[$tenantId] = true`;
- a parameter of a closure or an arrow function, as opposed to one of the method;
- a write that is not an assignment: `array_push($this->p, $x)`, `foreach (... as $this->p)`, a reference taken earlier;
- a write through another variable than `$this`. Static properties belong to `StaticStateLifetimeRule`.

It also reports two things that may surprise: a parameter overwritten before the write (`$value = 'x'; $this->p = $value;`), and every `$this` target of a destructuring whose right side reads a parameter. Assign the targets separately in the second case.

`lint:di` does not look at this at all: it reads only properties that carry `#[InjectAs*]` or `#[Config]`.

Another shape is reported for a different reason. A protected property with no injection attribute, typed as `Request` or as any type whose name ends in `Interface`, is flagged by `UnannotatedServicePropertyRule` as a missing injection attribute.

So the service still has to be written correctly: either the class is `#[ExecutionScoped]`, or it reads the context at call time from an injected store:

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

The opposite mistake is checked too. `ExecutionScopedWithoutAttributeRule` and `bin/semitexa lint:scoping` flag `#[InjectAsMutable]` on a service, contract implementation or repository that carries no scope attribute. Without one the class is built once per worker, so the property never receives per-execution values.

The inverse mistake is cheaper but still real: marking something execution-scoped that has no state at all just buys a clone per request for nothing. Shared is the default for a reason — see [readonly injection](readonly.md).
