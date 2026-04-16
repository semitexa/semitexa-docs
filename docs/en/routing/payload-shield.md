---
id: routing/payload-shield
section: routing
slug: payload-shield
title: Payload As A Shield
summary: Hydration happens before the handler, and each setter owns the normalization and guard logic for its own field.
order: 40
locale: en
status: canonical
keywords:
  - PayloadHydrator
  - ValidationException
  - setter guards
  - 422 before handler
---
# Payload As A Shield

Payloads are the shield from external data: hydration happens first, and each setter owns the normalization and guard logic for its own field before the handler runs.

## How it works

PayloadHydrator maps request input into the payload via typed setters. Each setter can normalize its value and throw a field-aware ValidationException when the input is invalid, which keeps the boundary close to the field itself.

## Why this matters

This keeps the transport boundary explicit without forcing one DTO-wide validation method to know every field. The payload owns the input truth, the handler owns the use case, and additional fields can be added by payload parts without reopening a central `validate()` method.
