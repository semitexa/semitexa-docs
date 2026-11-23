---
id: di/contract-resolution
section: di
slug: contract-resolution
title: Resolving Service Contracts
summary: Seeing which implementation is bound to a contract, using a resolver as a factory, and the Factory* naming convention for choosing by key.
order: 80
locale: en
status: canonical
verified_against: 2026.11.21.1434
keywords:
  - contract:list
  - SatisfiesServiceContract
  - resolver
  - Factory
---

# Resolving Service Contracts

Service contracts are interfaces bound to implementations in the **Semitexa DI container**. An implementation declares itself with **#[SatisfiesServiceContract(of: SomeInterface::class)]**. Contracts are one of several things the container holds: services, handlers, listeners and repositories are in it too, see [declaring a service](services.md). Dependencies arrive on protected properties with **#[InjectAsReadonly]**, **#[InjectAsMutable]** or **#[InjectAsFactory]** — see the [DI canon](overview.md) for the rules.

When several modules provide an implementation for the same interface, one of them is **active**:

- **A child module wins over its parent**, and only when the relation is declared: the child's `composer.json` names the parent in `extra.semitexa-module.extends`.
- **Between modules with no such relation, discovery order decides**: the project's own modules first, then packages, then vendor modules. There is no warning today, so two unrelated modules that implement the same interface resolve silently. Run `contracts:list` to see which one is active.

## Seeing which contract is bound to which class

**Command (for developers and AI agents):**

```bash
bin/semitexa contracts:list
```

This prints a table: for each **contract (interface)** you see all **implementations** (module → class) and which one is **active** (marked).

**Machine-readable output (for AI agents and scripts):**

```bash
bin/semitexa contracts:list --json
```

Output is JSON: `contracts[]` with `contract`, `active`, and `implementations` (each with `module` and `class`). Use this when debugging "which class is injected for interface X" or when generating/checking bindings.

## When to use

- Debugging: "Which implementation of ItemListProviderInterface is actually used?"
- After adding or removing a module: confirm the active implementation is the one you expect.
- AI agents: before changing a contract or adding an override, run `contracts:list` or `contracts:list --json` to see current bindings.

## How it works

- Implementations are discovered via **#[SatisfiesServiceContract(of: SomeInterface::class)]** on classes (in modules).
- **Single implementation:** the container binds the interface directly to that class.
- **Multiple implementations:** a **registry resolver** is generated in `src/registry/Contracts/` (e.g. `ItemListProviderResolver`). The resolver receives all implementations via constructor and exposes `getContract()`; the container uses it to obtain the chosen implementation. This generated class is the one place where the container passes constructor arguments. By default the resolver returns the implementation chosen by module order as described above; you can edit `getContract()` to pick another. `contracts:list` does not read the resolver: it marks as active the implementation module order selects, so after such an edit the container and the command can disagree. Resolver is **optional**: if the class does not exist, the container uses the registry’s active implementation directly.
- **Generate resolvers:** run **`bin/semitexa registry:sync:contracts`** (or **`bin/semitexa registry:sync`** to sync payloads and contracts together). Only interfaces with **2+ implementations** get a resolver; single-implementation contracts are not generated. The container discovers resolvers by convention (`App\Registry\Contracts\{InterfaceShortName}Resolver`), no manifest needed.
- **Canonical autoload mapping:** consumer apps should map **`App\Registry\`** to **`src/registry/`** in `autoload.psr-4`; legacy `src/Registry/Contracts/` directories should be migrated away before re-running contract sync so stale generated resolvers do not stay active.
- See `ServiceContractRegistry`, `RegistryContractResolverGenerator`, and `ModuleRegistry::getModuleOrderByExtends()` in the core package.

## Resolver as factory

The registry resolver is a **factory** in the usual sense: it receives all implementations via DI and exposes one method (`getContract()`) that returns the chosen instance. By default the generated code returns the implementation selected by module order, but you own the class and can change the logic:

- **Config-driven:** read a config key (e.g. `app.send_email.driver`) and return the implementation that matches.
- **Context-driven:** use request, tenant, or feature flags to pick an implementation.
- **Custom strategy:** merge, delegate, or switch between implementations inside `getContract()`.

There is no separate “Factory” pattern in Semitexa: service contracts with multiple implementations use this single mechanism. Document or name the resolver as a factory in your codebase if that helps (e.g. `SendEmailFactory` / `ItemListProviderResolver`).

## Factory* naming convention (choose implementation by key)

When you need to **choose** an implementation at runtime (by a backed-enum key) instead of always using the active one, define an interface whose short name **starts with `Factory`** in the same namespace as the base contract.

- **Example:** For `ItemListProviderInterface`, define a plain `FactoryItemListProviderInterface` with `getDefault()` and `get(ItemListProviderKind $key)` returning the base contract type; `keys()` returns the enum cases. Do **not** extend `Semitexa\Core\Contract\ContractFactoryInterface`: its `get()` takes `\BackedEnum`, and narrowing that to a concrete enum is a fatal error when PHP loads the interface (`semitexa.factoryContract` flags it).
- **Keys** are the cases of one backed enum: every implementation declares its own with `#[SatisfiesServiceContract(of: ..., factoryKey: ItemListProviderKind::Website)]`, and all keys must come from the same enum. Run **`bin/semitexa registry:sync:contracts`** to generate the implementation in `src/registry/Contracts/{ContractShortName}Factory.php`.
- **Usage:** Inject the Factory* interface where you need to pick; use `getDefault()` for the active implementation or `get(ItemListProviderKind::Website)` for a specific one. A string is not a key — `get()` takes the enum case. Details: [Factory Injection](factory.md).

`Semitexa\Core\Contract\ContractFactoryInterface` is the generic, untyped API — what a `ContractFactory` property injected with `#[InjectAsFactory(of: ...)]` exposes.
