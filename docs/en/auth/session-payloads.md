---
id: auth/session-payloads
section: auth
slug: session-payloads
title: Session Payloads
summary: Semitexa forbids string-key session chaos: session state lives in typed Session Payloads or it does not exist.
order: 20
locale: en
status: published
keywords:
  - "#[SessionSegment]"
  - typed session contract
  - no string keys
  - SessionInterface::getPayload()
---
# Session Payloads

Semitexa treats session state as a typed contract, not as an unstructured key-value dump.

## How it works

Every piece of session state belongs to a dedicated class marked with `#[SessionSegment]`. Handlers read and write session state through `SessionInterface::getPayload()` and `SessionInterface::setPayload()`. Arbitrary string key access is not available — if no Session Payload exists for a concern, that concern should not be writing to the session.

## Why this matters

String-key sessions rot. Keys drift across handlers, middleware, and listeners until nobody knows the real contract. Renaming one key becomes a distributed grep problem. Typed Session Payloads make the contract reviewable, refactor-safe, and local to a single class.
