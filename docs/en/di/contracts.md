---
id: di/contracts
section: di
slug: contracts
title: Service Contracts
summary: One module declares an interface, implementations advertise themselves with SatisfiesServiceContract, and the active one is visible in contracts:list.
order: 70
locale: en
status: canonical
keywords:
  - SatisfiesServiceContract
  - SatisfiesRepositoryContract
  - contracts:list
  - factoryKey
---
# Service Contracts

A service contract is an interface one module owns, and any module may implement. Implementations advertise themselves — nothing registers them by hand, and nothing looks them up by string.

```php
#[AsService]
#[SatisfiesServiceContract(of: CollectionQueryCompilerInterface::class)]
final class CollectionQueryCompiler implements CollectionQueryCompilerInterface
```

That is `Semitexa\Orm\Query\CollectionQueryCompiler`. Consumers keep depending on the interface:

```php
#[InjectAsReadonly]
protected CollectionQueryCompilerInterface $compiler;
```

`#[SatisfiesServiceContract]` also makes the class container-managed by itself, so the `#[AsService]` above is a statement of role rather than a requirement.

## Two attributes, one idea

| Attribute | Arguments | For |
|---|---|---|
| `#[SatisfiesServiceContract]` | `of`, `factoryKey` | service capabilities |
| `#[SatisfiesRepositoryContract]` | `of` | repository capabilities — see [repository workflow](../data/repository-workflow.md) |

The repository variant has no `factoryKey`: a repository contract has one active implementation, not a keyed set.

## Seeing who won

When two modules implement the same contract, one is active. Do not guess:

```bash
bin/semitexa contracts:list
```

```text
  Contract (interface)              Implementations (module → class)                        Active
  FormCollabDraftStoreInterface     semitexa-platform-site → ShowcaseFormCollabDraftStore ✓  ShowcaseFormCollabDraftStore
                                    semitexa-platform-ui   → FormCollabDraftDbRepository
```

The ✓ marks the active implementation and the second row shows the one it displaced. [Contract resolution](contract-resolution.md) covers how that choice is made and how to override it.

## When several implementations must coexist

If callers need to choose an implementation at call time rather than one being active, every implementation declares an enum-backed `factoryKey` and consumers inject a factory instead:

```php
#[SatisfiesServiceContract(of: StorageInterface::class, factoryKey: StorageKey::S3)]
```

This is all-or-nothing: miss the key on one implementation and boot fails with a named error. See [factory injection](factory.md).

## Why attributes rather than a config file

The declaration lives on the class that makes the promise, so a reader who has the implementation open can see what it claims to satisfy, and a reader who has the interface open can run one command to see every claimant. There is no registry file to drift out of step with the code, and no string id to typo.
