---
id: data/domain-models
section: data
slug: domain-models
title: Domain-Level Models
summary: Semitexa separates persistence resources from business models. Resources map tables; domain models carry behavior and invariants.
order: 10
locale: en
status: canonical
keywords:
  - ResourceModel
  - mapper
  - "#[AsMapper]"
  - "#[SatisfiesRepositoryContract]"
  - DomainRepository
---
# Domain-Level Models

Semitexa keeps persistence resources and business domain models as separate classes with explicit mappers between them.

## How it works

A `ResourceModel` describes how one table row is stored and rehydrated. A domain model holds business semantics — methods like `revoke()`, `rotateSecretHash()`, `recordUsage()`, and `hasScope()`. A mapper annotated with `#[AsMapper]` converts between the two, and a repository implementation annotated with `#[SatisfiesRepositoryContract]` returns domain models from the contract boundary.

## Why this matters

When one class is both an ORM mapping and a business model, storage concerns leak into business code immediately. Semitexa keeps the separation explicit so that handlers and services work with objects that carry business meaning, not persistence metadata.
