---
id: api/structured-errors
section: api
slug: structured-errors
title: Structured Errors
summary: Throw domain exceptions and let semitexa-api map them into stable machine-readable error envelopes.
order: 20
locale: en
status: published
keywords:
  - ExternalApiExceptionMapper
  - DomainException
  - error.context
  - request_id
---
# Structured Errors

API failures should stay operationally useful. The `ExternalApiExceptionMapper` intercepts any `DomainException` thrown from an `#[ExternalApi]` route and transforms it into a predictable JSON error envelope before the response leaves the framework.

## How it works

Domain exceptions carry typed context — field errors for validation failures, `retry_after` for rate limits, resource identifiers for not-found cases. The mapper reads that context and places it under `error.context` in the response body. The HTTP status code maps directly from the exception type. The outer envelope shape is always `{ error: { code, message, context, request_id, docs_url } }`.

## Why this matters

Clients can rely on error structure even when the business path fails. Validation problems stay nested under `error.context.fields`, auth failures keep the same outer shape with only the status changing, and retry guidance appears in both the `Retry-After` header and the machine-readable body. SDKs and dashboards can branch on `error.code` with one parser instead of two.
