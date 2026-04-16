---
id: routing/parameterized
section: routing
slug: parameterized
title: Parameterized Route
summary: Path parameters with regex constraints and typed injection.
order: 20
locale: en
status: canonical
keywords:
  - requirements
  - defaults
  - PayloadHydrator
  - setter injection
---
# Parameterized Route

Path parameters like `{slug}` are extracted from the URL and injected into the payload DTO via setter methods.

## How it works

The router uses regex requirements to constrain what each parameter matches. The PayloadHydrator calls setters on the payload DTO with the extracted values. Default values are used when a parameter is not present.

## Why this matters

Typed path parameters with regex constraints prevent invalid data from reaching handler code. The handler can trust that `$payload->slug` is always a valid, matched value.
