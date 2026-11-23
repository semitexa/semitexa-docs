---
id: di/overview
section: di
slug: overview
title: DI Canon
summary: One canonical DI path for container-managed classes: protected property attributes, no constructor arguments, validated at boot and checked by lint:di and PHPStan.
order: 10
locale: en
status: canonical
verified_against: 2026.11.21.1434
keywords:
  - InjectAsReadonly
  - InjectAsMutable
  - InjectAsFactory
  - Config
  - lint:di
---
# DI Canon

The classes you write get their dependencies through one channel when the container manages them: **protected properties carrying an injection attribute**.

```semitexa-diagram
title: Container resolution at worker boot
node: discovery | Class discovery | Finds managed classes | 0 | 0
node: metadata | Injection metadata | Reads property attributes | 1 | 0
node: bindings | Contract bindings | Selects implementations | 2 | 0
node: instance | Managed instance | Injects protected properties | 3 | 0
node: sealed | Sealed container | Serves stable workers | 4 | 0
edge: discovery -> metadata | inspect
edge: metadata -> bindings | resolve
edge: bindings -> instance | construct
edge: instance -> sealed | validate
```

```php
#[AsService]
final class WebhookConfig
{
    #[Config(env: 'WEBHOOK_TIMEOUT_SECONDS', default: 30)]
    protected int $defaultTimeoutSeconds;

    #[Config(env: 'WEBHOOK_MAX_ATTEMPTS', default: 5)]
    protected int $defaultMaxAttempts;
}
```

That is `Semitexa\Webhooks\Configuration\WebhookConfig`, trimmed to two of its properties.

Four attributes, and nothing else, feed a container-managed object:

| Attribute | Target | What arrives |
|---|---|---|
| `#[InjectAsReadonly]` | property | the worker-scoped shared instance — see [readonly](readonly.md) |
| `#[InjectAsMutable]` | property | the execution-scoped clone — see [mutable](mutable.md) |
| `#[InjectAsFactory]` | property | a `ContractFactory` selecting among implementations — see [factory](factory.md) |
| `#[Config]` | property | a scalar read from the environment — see [configuration](configuration.md) |

## The constructor rule, exactly

The container builds container-managed objects with `newInstanceWithoutConstructor()`. Two consequences follow, and they are easy to conflate:

- **A `__construct` with parameters is rejected.** The container treats it as an attempt to smuggle in a second DI channel and refuses to build the class.
- **A parameterless `__construct` is never called.** An empty one is allowed. One with a body is reported by `lint:di`, because that body never runs.

Initialisation that needs the injected dependencies goes into `initialize()`: implement `Semitexa\Core\Contract\InitializesAfterInjectionInterface` and the container calls it once per instance, after every injected property is filled and before anything else receives the object. On an execution-scoped class that is once per execution, so keep I/O out of it. A value that needs no dependency can stay in the property declaration.

Constructors are entirely unrestricted on anything the container does not manage — value objects, DTOs, payloads, resources, entities. The rule is about container-managed classes, not about PHP.

One exception is generated, not written by hand: a `Factory*` class in `src/registry/Contracts/` receives the container's generic factory for its contract through its constructor. Which implementation of a contract is active is the registry's choice alone, see [resolving service contracts](contract-resolution.md).

## Boot validation, and the two tools that enforce it

The graph is validated at boot, so an unresolvable dependency fails the worker rather than the first request that touches it. The container is also **sealed after boot**: calling `set()` afterwards throws `ContainerSealedException`. There is no runtime registration.

Injection is the rule, but a lookup by class name still exists. `Psr\Container\ContainerInterface` is itself injectable, and code that the container does not manage can read an execution-scoped service at the point of use with `RequestScopedContainer::forCurrentExecution($this->container)->get(Foo::class)`. What is rejected is the static route: `StaticContainerAccessRule` flags a `ContainerFactory::` call outside the framework's own internals.

You do not have to wait for boot to find out:

```bash
bin/semitexa lint:di
```

```text
 [OK] All 363 container-managed classes pass DI lint.
```

The number is your project's own. It counts the container-managed classes and the console commands. Commands are not container-managed, so they get one of the checks only: `#[InjectAsReadonly]` on an execution-scoped type.

`lint:di` reports one finding per class or property for what it can see on a container that built:

- a parameterless `__construct` with a body;
- a nullable injected property;
- `#[InjectAsReadonly]` on an execution-scoped type.

Two things it does not report that way:

- **These stop the container from building:** a constructor with parameters; two injection attributes on one property; an injected property that is private or public, or declared in a trait; `#[Config]` on an array or a class that is not a backed enum; `#[InjectAs*]` on a scalar. `lint:di` is itself a container-built command, so it never reaches its own checks. It prints `The container did not build, so commands that need it cannot run, and a server restarted now would not start.` followed by the container's message, which names the class. The build stops at the first such class, so fix it and run again to see the next.
- **A property with no injection attribute is not checked at all.** `lint:di` only looks at properties that carry `#[InjectAs*]` or `#[Config]`. A bare typed property is PHPStan's to find.

PHPStan rules cover what needs the source rather than a built container. The ones you are most likely to meet:

| Rule | Rejects |
|---|---|
| `StaticContainerAccessRule` | a `ContainerFactory::` call outside the framework's internals |
| `UnannotatedServicePropertyRule` | a protected property with no injection attribute, on a container-managed class, whose type name ends in `Interface` or is one of the per-request context types such as `Request` |
| `ExecutionScopedWithoutAttributeRule` | `#[InjectAsMutable]` on a service, contract implementation or repository that carries no scope attribute |
| `WorkerServiceCallerStateRule` | a property of a worker-shared service, with no `#[InjectAs*]` and no `#[Config]`, assigned through `$this` from an expression that reads a parameter of the enclosing method (any method but `__construct`); `#[WorkerState('why')]` on the property opts out |
| `TraitInjectionRule` | injection attributes inside a trait — declare them in the consuming class |

`UnannotatedServicePropertyRule` is narrower than its name: a bare property typed as a concrete class, such as `protected SharedCounter $shared;`, passes both PHPStan and `lint:di`, stays unset, and fails at its first read.

`WorkerServiceCallerStateRule` (identifier `semitexa.workerServiceCallerState`) covers one shape of per-request data kept on a worker-shared service: a value a caller passed in. Up to semitexa/core 2026.11.21.1434 nothing checked this; the release after it adds the rule. Fix a report by reading the context at call time, or mark a property that really is one per worker with `#[WorkerState('why')]`. Its silence is not proof. It does not report a service that reads the context itself and keeps it, a parameter copied to a local variable before the assignment, or a method inherited from a trait or a base class. See [what goes wrong without execution scope](mutable.md) for the examples and the full list.

## Why one path

The goal is not flexibility. In a long-running Swoole worker, mixed DI styles turn into boot fragility and cross-request state leaks. One visible channel keeps the dependency graph locally readable, lets boot validation and static analysis reject ambiguity before runtime, and makes large refactors survivable.
