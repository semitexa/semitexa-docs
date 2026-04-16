---
id: api/rest-graphql
section: api
slug: rest-graphql
title: REST + GraphQL
summary: One Semitexa use case can serve both REST and GraphQL without duplicating handler logic into separate resolver classes.
order: 70
locale: en
status: published
keywords:
  - REST + GraphQL
  - "#[ExposeAsGraphql]"
  - shared use case
  - no duplicated logic
---
# REST + GraphQL

Semitexa lets one use case answer both transports, so teams do not have to choose between REST and GraphQL too early.

## How it works

A Payload DTO marked with both `#[ExternalApi]` and `#[ExposeAsGraphql]` registers the same operation into both the REST route table and the GraphQL schema. The handler is written once. The framework dispatches the same handler for both an HTTP `GET` request and a `POST /graphql` query targeting the same field. The output DTO is the same in both cases.

## Why this matters

Transport-level decisions are often made before the product is stable. Semitexa's dual-transport model delays that commitment: you write one handler now and surface it as REST or GraphQL or both later. The alternative — maintaining separate handler and resolver trees for each transport — creates divergence that grows with every new field or behavior change.
