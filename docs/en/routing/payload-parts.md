---
id: routing/payload-parts
section: routing
slug: payload-parts
title: Payload Parts
summary: One module owns the route, another module can extend the same payload contract without forking or reopening the base class.
order: 50
locale: en
status: canonical
keywords:
  - "#[AsPayloadPart]"
  - PayloadFactory
  - trait composition
  - module extension
  - field-level guards
---
# Payload Parts

A payload can be extended by another module without reopening the original route class, so one transport boundary can stay singular while modules stay additive.

## How it works

A base module declares the payload with one of the access attributes (`#[AsPublicPayload]`, `#[AsProtectedPayload]`, or `#[AsServicePayload]`). Another module contributes a trait marked with `#[AsPayloadPart(base: ...)]`. At runtime PayloadFactory composes a wrapper class that extends the base payload and uses all matching traits, so the added trait can own setters and guards for its own extra fields.

## Why this matters

This solves a painful modularity problem: extra request concerns do not force a fork of the original payload and do not leak into untyped arrays. The handler still receives one trusted DTO, and each added concern can validate its own field without a central `validate()` choke point.
