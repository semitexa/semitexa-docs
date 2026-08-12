---
id: di/overview
section: di
slug: overview
title: DI Canon
summary: One canonical DI path for container-managed classes — protected property attributes, no constructor arguments, validated at boot and enforced by lint:di.
order: 10
locale: en
status: canonical
keywords:
  - InjectAsReadonly
  - InjectAsMutable
  - InjectAsFactory
  - Config
  - lint:di
---
# DI Canon

Semitexa has exactly one dependency injection channel for container-managed classes: **protected properties carrying an injection attribute**.

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
- **A parameterless `__construct` is tolerated but never called.** This is the one that bites: initialisation you put there silently does not run. Initialise in property declarations instead.

Constructors are entirely unrestricted on anything the container does not manage — value objects, DTOs, payloads, resources, entities. The rule is about container-managed classes, not about PHP.

## Boot validation, and the two tools that enforce it

The graph is validated at boot, so an unresolvable dependency fails the worker rather than the first request that touches it. The container is also **sealed after boot**: calling `set()` afterwards throws `ContainerSealedException`. There is no runtime registration and no service locator.

You do not have to wait for boot to find out:

```bash
bin/semitexa lint:di
```

```text
 [OK] All 363 container-managed classes pass DI lint.
```

It reports the precise reason per class — a constructor with parameters, an unannotated service property, two competing injection attributes on one property.

PHPStan carries the same rules into the editor. The ones you are most likely to meet:

| Rule | Rejects |
|---|---|
| `StaticContainerAccessRule` | reaching for the container statically instead of injecting |
| `UnannotatedServicePropertyRule` | a service-typed property with no injection attribute |
| `ExecutionScopedWithoutAttributeRule` | per-execution state on a class that never opted into execution scope |
| `TraitInjectionRule` | injection attributes inside a trait — declare them in the consuming class |

## Why one path

The goal is not flexibility. In a long-running Swoole worker, mixed DI styles turn into boot fragility and cross-request state leaks. One visible channel keeps the dependency graph locally readable, lets boot validation and static analysis reject ambiguity before runtime, and makes large refactors survivable.
