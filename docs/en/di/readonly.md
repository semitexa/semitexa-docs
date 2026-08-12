---
id: di/readonly
section: di
slug: readonly
title: Readonly Injection
summary: The default tier — one instance per worker, injected into a protected property, with optional injection for dependencies that may not be installed.
order: 30
locale: en
status: canonical
keywords:
  - InjectAsReadonly
  - worker-scoped
  - optional
  - InjectionException
---
# Readonly Injection

`#[InjectAsReadonly]` is the tier you want unless you have a reason to want another. The container resolves the dependency once during boot and hands every consumer the same instance for the life of the worker process.

```php
#[InjectAsReadonly]
protected ResourceMetadataRegistry $registry;
```

Three structural requirements, all enforced:

- The property is **protected**. Private hides it from the injector; public invites mutation from outside.
- The type is a **named class or interface**. Union types, `mixed` and scalars have nothing to resolve.
- The declaration is **in the class itself**, not in a trait — `TraitInjectionRule` rejects injection attributes inside traits.

There is no assignment and no initialisation: the property is declared and left alone. The container fills it before anything can call the object.

## When the binding is missing

By default this is a hard failure at boot, not a null at runtime:

> `Cannot inject {class}::${property}: container has no binding for {type}.`

That is deliberate. A missing dependency stops the worker starting rather than surfacing as a `null` dereference on whichever request first touches that path.

## Optional injection

Two ways to say "fill this if you can":

```php
#[InjectAsReadonly(optional: true)]
protected ?SearchIndexer $indexer;
```

A **nullable property type is itself recorded as optional**, so `?SearchIndexer` behaves the same way without the argument. Either way, when the container has no binding the property is simply skipped.

The escape hatch is narrow on purpose. Only the *missing binding* case is forgiven — the structural rules above still throw, because a private property or an untyped one is a mistake that a default of `null` would only hide.

Reach for it when a dependency comes from a package that may not be installed. Do not reach for it to quieten a boot error you have not read.

## Readonly cannot depend on execution-scoped

Injecting an execution-scoped type into a readonly property throws. The reason is structural: the shared instance would capture one execution's clone and hand it to every later execution — precisely the cross-request contamination the tiers exist to prevent. If a shared service needs per-request data, it should receive it as a method argument, or the class itself belongs in the [execution-scoped tier](mutable.md).
