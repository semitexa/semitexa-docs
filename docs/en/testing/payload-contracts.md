---
id: testing/payload-contracts
section: testing
slug: payload-contracts
title: Payload Contract Testing
summary: Scaffold one project-level contract test and let strategy profiles verify payload boundaries without hand-writing repetitive negative cases.
order: 10
locale: en
status: canonical
keywords:
  - "#[TestablePayload]"
  - test:run
  - StrictProfileStrategy
  - MonkeyTestingStrategy
---
# Payload Contract Testing

Automated contract testing for payloads -- `#[TestablePayload]` marks a payload for strategy-based validation.

## How it works

The testing framework discovers testable payloads, applies strategy profiles (Standard, Strict, Paranoid), and runs security, type enforcement, and monkey testing strategies against each endpoint. The semitexa-testing package ships its own `ProjectPayloadsContractTest` integration test (`packages/semitexa-testing/tests/Integration/`) that auto-discovers every `#[TestablePayload]`-marked payload in the host project, so no per-project scaffolding is required.

## Why this matters

Contract tests verify that payloads reject bad input and accept good input without writing individual test cases. Strategy profiles let teams choose their risk tolerance -- Strict catches common boundary mistakes, while Paranoid adds monkey input and deeper type mutation coverage.
