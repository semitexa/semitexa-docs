---
id: di/services
section: di
slug: services
title: Declaring a Service
summary: #[AsService] makes a plain class a worker-scoped singleton that the container builds and injects, and this page shows when you do not need it.
order: 20
locale: en
status: canonical
verified_against: 2026.11.21.1434
keywords:
  - AsService
  - make:service
  - worker-scoped singleton
---
# Declaring a Service

`#[AsService]` marks a class as a **worker-scoped singleton**. The container discovers it, builds it once per worker, and injects it wherever a property asks for that type with `#[InjectAsReadonly]`.

```php
use Semitexa\Core\Attribute\AsService;

#[AsService]
final class WorkflowDefinitionRegistry
{
    // dependencies arrive on properties, never through __construct
}
```

The attribute takes no arguments. There is no service id, no alias, no tag — the class name *is* the identity, and the type on the injecting property is the lookup key.

Scaffold one with:

```bash
bin/semitexa make:service
```

## When you do not need it

`#[AsService]` is for **plain service classes that carry no other role**. A class is container-managed if it carries any of these, and adding `#[AsService]` on top is redundant:

| Attribute | Example |
|---|---|
| `#[AsPayloadHandler]` | HTTP handlers |
| `#[AsEventListener]` | domain event listeners |
| `#[AsPipelineListener]` | request pipeline listeners |
| `#[AsRepository]` (ORM) | repositories |
| `#[SatisfiesServiceContract]` | contract implementations |
| `#[SatisfiesRepositoryContract]` | repository contract implementations |
| `#[AsAuthHandler]` (auth) | authentication handlers |
| `#[AsSlotHandler]` (SSR) | slot handlers |
| an attribute that implements `ImpliesService` | a package's own marker for its services |

`lint:di` walks the first six rows only. Auth handlers, slot handlers and `ImpliesService` carriers are built by the container, and a mistake in one still fails the container build, but `lint:di` does not list them among the classes it checked.

**`#[AsCommand]` is deliberately not on it.** Console commands are not container-managed, so `lint:di` runs only one of its checks on them, `#[InjectAsReadonly]` on an execution-scoped type, and counts them in its total. A command still cannot take its dependencies through the constructor: console boot does not resolve constructor arguments, and a command that asks for them is skipped at boot with a warning that names the fix. Declare them as protected properties with `#[InjectAsReadonly]`, as a service does.

The one combination that *is* idiomatic is pairing it with a contract declaration, because the two say different things — this class is a service, and it satisfies that capability:

```php
#[AsService]
#[SatisfiesServiceContract(of: CollectionQueryCompilerInterface::class)]
final class CollectionQueryCompiler implements CollectionQueryCompilerInterface
```

That is `Semitexa\Orm\Query\CollectionQueryCompiler`. See [service contracts](contracts.md) for what the second attribute buys you.

## Worker-scoped means shared

One instance serves every request handled by that worker, for the life of the process. Two consequences:

- **Do not store per-request state on it.** A property you mutate during a request is still there for the next one, and under concurrency another request reads it while yours is still running. PHPStan's `WorkerServiceCallerStateRule` (identifier `semitexa.workerServiceCallerState`) reports one shape of this: a property with no `#[InjectAs*]` and no `#[Config]` that a method other than `__construct` assigns through `$this`, directly or as an array element, from an expression that reads one of its own parameters, such as `$this->tenantId = $tenantId`. Up to semitexa/core 2026.11.21.1434 nothing checked this; the release after it adds the rule. Two fixes: read the context at call time, by injecting `TenantContextStoreInterface` and calling `$this->tenantStore->tryGet()` inside the method (or put the class in the [execution-scoped tier](mutable.md) with `#[ExecutionScoped]`; its consumers then take it with `#[InjectAsMutable]`, since injecting an execution-scoped type into a readonly property throws at boot, and a worker-shared consumer receives the data as a method argument or reads it from the context at call time instead, see [readonly](readonly.md)); or, when the value really is one per worker, such as a registry filled at boot, mark the property `#[WorkerState('why')]`. A clean run is not proof: the rule does not report a service that reads the context itself and keeps it, a parameter copied to a local variable before the assignment, or a method inherited from a trait or a base class, and `lint:di` does not look at this at all. [That page](mutable.md) shows the examples and the full list.
- **Do not hold a connection handle across requests.** `WorkerServiceConnectionHandleRule` flags this: acquire from the pool inside the call, or use a coroutine-local if it must span calls within one request.

Object identity is stable across requests, which is exactly why a registry, a compiler or a cache belongs here.
