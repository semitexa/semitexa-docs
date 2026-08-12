---
id: di/services
section: di
slug: services
title: Declaring a Service
summary: "#[AsService] makes a plain class a worker-scoped singleton the container will build and inject — and when you do not need it."
order: 20
locale: en
status: canonical
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

That list is exhaustive — it is `LintDiCommand::CONTAINER_MANAGED_ATTRIBUTES`, and it is what `lint:di` walks.

**`#[AsCommand]` is deliberately not on it.** Console commands are not container-managed, so the constructor rule does not apply to them: a command may take constructor arguments like any ordinary class. If you have been wondering why a command can do what a service cannot, that is why.

The one combination that *is* idiomatic is pairing it with a contract declaration, because the two say different things — this class is a service, and it satisfies that capability:

```php
#[AsService]
#[SatisfiesServiceContract(of: CollectionQueryCompilerInterface::class)]
final class CollectionQueryCompiler implements CollectionQueryCompilerInterface
```

That is `Semitexa\Orm\Query\CollectionQueryCompiler`. See [service contracts](contracts.md) for what the second attribute buys you.

## Worker-scoped means shared

One instance serves every request handled by that worker, for the life of the process. Two consequences:

- **Do not store per-request state on it.** A property you mutate during a request is still there for the next one. If the class genuinely needs per-execution state, it belongs in the [execution-scoped tier](mutable.md) instead, and PHPStan's `ExecutionScopedWithoutAttributeRule` will say so.
- **Do not hold a connection handle across requests.** `WorkerServiceConnectionHandleRule` flags this: acquire from the pool inside the call, or use a coroutine-local if it must span calls within one request.

Object identity is stable across requests, which is exactly why a registry, a compiler or a cache belongs here.
