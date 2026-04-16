---
id: api/schema-discovery
section: api
slug: schema-discovery
title: Schema Discovery
summary: A mini Swagger-style explorer for the live product API contract, schema endpoint, and response shapes.
order: 50
locale: en
status: published
keywords:
  - "#[ExternalApi]"
  - application/schema+json
  - JSON Schema
  - live explorer
---
# Schema Discovery

A machine-facing API should explain its own shape and let you exercise the contract without leaving the demo.

## How it works

The product API exposes a dedicated `_schema` endpoint that returns the full JSON Schema for the product representation. The endpoint responds to both `application/json` and `application/schema+json` Accept headers, so machine tooling and human browsers get the same document at the right content type. The schema explorer on this page exercises the live contract: schema endpoint, sparse fieldset detail, and expanded graph with category and review data.

## Why this matters

API clients should not have to reverse-engineer the response shape from examples. A schema endpoint gives SDK generators, validation layers, and developer tooling a single source of truth. Responding to `application/schema+json` makes the endpoint machine-discoverable through standard tooling without a separate OpenAPI pipeline.
