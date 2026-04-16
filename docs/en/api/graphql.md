---
id: api/graphql
section: api
slug: graphql
title: GraphQL API
summary: GraphQL-first Semitexa contracts built with typed payloads and typed output DTOs instead of resolver sprawl.
order: 60
locale: en
status: published
keywords:
  - POST /graphql
  - "#[ExposeAsGraphql]"
  - typed output DTOs
  - GraphQL-first
---
# GraphQL API

If your public API is GraphQL-first, Semitexa still keeps the application layer explicit and typed.

## How it works

A GraphQL-only endpoint uses `#[ExposeAsGraphql]` on the Payload DTO to register the operation into the GraphQL schema under a declared field name and root type (`Query` or `Mutation`). The handler returns a typed output DTO — a plain PHP class — and the framework serializes it into the GraphQL response shape. No resolver classes, no schema-first string parsing, no separate type registry to maintain.

## Why this matters

GraphQL-first Semitexa APIs still use typed payloads and typed output DTOs. The application logic lives in the handler, which keeps business code away from transport concerns. Adding a new GraphQL field means adding one Payload DTO with the attribute — the schema updates automatically on the next boot.
