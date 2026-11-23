---
id: di/contract-resolution
section: di
slug: contract-resolution
title: Resolving Service Contracts
summary: Seeing which implementation is bound to a contract, how the registry alone chooses it, and the Factory* naming convention for choosing by key.
order: 80
locale: en
status: canonical
verified_against: 2026.11.23.1215
keywords:
  - contract:list
  - SatisfiesServiceContract
  - Factory
---

# Resolving Service Contracts

Service contracts are interfaces bound to implementations in the **Semitexa DI container**. An implementation declares itself with **#[SatisfiesServiceContract(of: SomeInterface::class)]**. Contracts are one of several things the container holds: services, handlers, listeners and repositories are in it too, see [declaring a service](services.md). Dependencies arrive on protected properties with **#[InjectAsReadonly]**, **#[InjectAsMutable]** or **#[InjectAsFactory]** — see the [DI canon](overview.md) for the rules.

When several modules provide an implementation for the same interface, one of them is **active**:

- **A child module wins over its parent**, and only when the relation is declared: the child's `composer.json` names the parent in `extra.semitexa-module.extends`.
- **Between modules with no such relation, discovery order decides**: the project's own modules first, then packages, then vendor modules; Core's own implementation loses to any module.
- **Two exceptions to that order, today.** Check `contracts:list` after you declare a relation:
  - a module that another module extends is placed after every module nobody extends, packages and vendor modules included, so an unrelated package can win a contract over a project module that has a child;
  - modules whose `extends` relations form a cycle get no place in the order and rank with Core, so among them and Core's default the implementation found first wins.
- **`contracts:list` names what decided each contract** with two or more implementations (on `develop`, after 2026.11.23.1215). In `--json`, `decided_by` is one of these values, and `tied_with` lists the implementations that lost only by discovery order:
  - `extends`: a declared relation;
  - `project-module`: a project module over a package;
  - `core-default`: any module over Core;
  - `discovery-order`: no declared relation, tier or Core default separates them, so the module order decided: the one found first won, or one of the two exceptions above did (a module placed late because another extends it, a module in a cycle).
- **`lint:di` warns**, without failing, when the active implementation and one it tied with are in two different project modules (on `develop`, after 2026.11.23.1215). A tie between packages, or a contract a package wins, shows in `contracts:list` with no warning.

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
- **Multiple implementations:** the **registry alone** decides which one is active (on `develop`, after 2026.11.23.1215), by module order as described above. The container binds the interface to it everywhere (`get()`, injected properties, a factory's `getDefault()`), and `contracts:list` shows the same choice and what decided it. To make another one active, declare the relation (`extends`) in the module's `composer.json`. To choose one at runtime, use a `Factory*` interface (below).
- **Sync:** **`bin/semitexa registry:sync:contracts`** (or **`bin/semitexa registry:sync`** to sync payloads and contracts together) generates the `Factory*` implementations for contracts with **2+ implementations**. Earlier versions also generated a resolver per such contract (`src/registry/Contracts/{InterfaceShortName}Resolver.php`); the container no longer reads one, and the sync removes the ones it generated. A file without the generated header stays.
- **Canonical autoload mapping:** consumer apps should map **`App\Registry\`** to **`src/registry/`** in `autoload.psr-4`; legacy `src/Registry/Contracts/` directories should be migrated away before re-running contract sync, so the generated classes stay in one place.
- See `ServiceContractRegistry`, `RegistryContractResolverGenerator`, and `ModuleRegistry::getModuleOrderByExtends()` in the core package.

## Factory* naming convention (choose implementation by key)

When you need to **choose** an implementation at runtime (by a backed-enum key) instead of always using the active one, define an interface whose short name **starts with `Factory`** in the same namespace as the base contract.

- **Example:** For `ItemListProviderInterface`, define a plain `FactoryItemListProviderInterface` with `getDefault()` and `get(ItemListProviderKind $key)` returning the base contract type; `keys()` returns the enum cases. Do **not** extend `Semitexa\Core\Contract\ContractFactoryInterface`: its `get()` takes `\BackedEnum`, and narrowing that to a concrete enum is a fatal error when PHP loads the interface (`semitexa.factoryContract` flags it).
- **Keys** are the cases of one backed enum: every implementation declares its own with `#[SatisfiesServiceContract(of: ..., factoryKey: ItemListProviderKind::Website)]`, and all keys must come from the same enum. Run **`bin/semitexa registry:sync:contracts`** to generate the implementation in `src/registry/Contracts/{ContractShortName}Factory.php`.
- **Usage:** Inject the Factory* interface where you need to pick; use `getDefault()` for the active implementation or `get(ItemListProviderKind::Website)` for a specific one. A string is not a key — `get()` takes the enum case. Details: [Factory Injection](factory.md).

`Semitexa\Core\Contract\ContractFactoryInterface` is the generic, untyped API — what a `ContractFactory` property injected with `#[InjectAsFactory(of: ...)]` exposes.
