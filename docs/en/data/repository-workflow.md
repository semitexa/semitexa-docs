---
id: data/repository-workflow
section: data
slug: repository-workflow
title: Repository Workflow
summary: The canonical Semitexa path: handlers depend on repository contracts, repositories return domain models, and persistence resources stay behind the boundary.
order: 20
locale: en
status: canonical
keywords:
  - repository contract
  - domain model
  - ResourceModel
  - mapper
  - "#[SatisfiesRepositoryContract]"
---
# Repository Workflow

The canonical Semitexa persistence path keeps business code working with domain models and confines ResourceModel and mapper logic to the persistence layer.

## How it works

Handlers receive repository dependencies through `#[InjectAsReadonly]`, preferably via contract interfaces where the module defines them. The repository implementation performs the read via `ResourceModel` → mapper → domain model, and persists through `insert(domainModel)` or `update(domainModel)`. Low-level ResourceModel reads remain available but are an explicit infrastructure concern.

## Why this matters

When business code depends on repository contracts and domain models, the storage mapping stays reviewable and isolated. Swapping a persistence implementation never forces changes in handlers or services, and the business rules stay on the business objects where they belong.
