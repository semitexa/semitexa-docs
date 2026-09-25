---
id: di/factory
section: di
slug: factory
title: Factory Injection
summary: "#[InjectAsFactory] injects a factory that selects among a contract's implementations by backed-enum key — not a closure; execution-scoped implementations come back as a fresh per-execution instance."
order: 50
locale: en
status: canonical
verified_against: 2026.09.19.1020
keywords:
  - InjectAsFactory
  - ContractFactory
  - factoryKey
  - BackedEnum
---
# Factory Injection

`#[InjectAsFactory]` is for the case where one contract has **several implementations and the caller picks which one**. The property receives a factory object built at boot. Type it one of two ways:

```php
// The contract's generated Factory* interface: typed get() / getDefault().
#[InjectAsFactory]
protected FactoryStorageInterface $storage;

// The generic factory: name the contract with `of`, since the type alone cannot.
#[InjectAsFactory(of: StorageInterface::class)]
protected ContractFactory $storage;
```

A `ContractFactory` (or `ContractFactoryInterface`) property without `of` is rejected at boot — the type does not say which contract it wants. The typed form needs the generated `App\Registry\Contracts\*Factory` class; if it is missing or out of date the worker refuses to boot and tells you to run `bin/semitexa registry:sync:contracts`.

Two things to know about what you get:

- It is **not a closure**. You get an object with a small API, not something you invoke.
- It **selects, it does not construct** — except for `#[ExecutionScoped]` implementations, which come back as a fresh per-execution instance (with their `#[InjectAsMutable]` dependencies bound), exactly as `container->get()` would hand them out. Worker-scoped implementations are the same shared instances every time.

## The API

```php
$this->storage->getDefault();          // the active implementation
$this->storage->get(StorageKey::S3);   // a specific one, by backed-enum case
$this->storage->keys();                // list<BackedEnum> — every declared key
```

`get()` takes a `BackedEnum`, not a string. That is the closed-world part: the set of choices is a PHP enum, so an invalid selection is a type error at author time rather than a lookup miss at runtime.

## Declaring the implementations

A factory exists for a contract only when its implementations declare a `factoryKey`:

```php
#[SatisfiesServiceContract(of: StorageInterface::class, factoryKey: StorageKey::Local)]
final class LocalStorage implements StorageInterface {}

#[SatisfiesServiceContract(of: StorageInterface::class, factoryKey: StorageKey::S3)]
final class S3Storage implements StorageInterface {}
```

The rule is all-or-nothing and enforced at boot:

> `Factory contract {interface} requires enum-backed factoryKey for every implementation. Missing on {class}.`

Every implementation of that contract must carry a `factoryKey`, and all keys must come from the same enum class. Miss one and the worker refuses to boot — which is the intended failure, because a partially keyed contract has no well-defined key space.

## When to reach for it

Only when the choice is genuinely made at call time by the calling code. If the choice is made once per deployment, that is not a factory — declare the implementations normally and let [contract resolution](contract-resolution.md) pick the active one.

No package in the framework currently injects one; the machinery is implemented and boot-validated, but the pattern is rare by design. Prefer a single active implementation until you can name the caller that needs to switch.
