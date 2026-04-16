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
  - test:init
  - test:run
  - StrictProfileStrategy
  - MonkeyTestingStrategy
---
# Payload Contract Testing

Automated contract testing for payloads -- `#[TestablePayload]` marks a payload for strategy-based validation.

## How it works

The testing framework discovers testable payloads, applies strategy profiles (Standard, Strict, Paranoid), and runs security, type enforcement, and monkey testing strategies against each endpoint. Scaffold one universal `ProjectPayloadsContractTest` with `test:init`, and the suite auto-discovers every marked payload in the project.

## Why this matters

Contract tests verify that payloads reject bad input and accept good input without writing individual test cases. Strategy profiles let teams choose their risk tolerance -- Strict catches common boundary mistakes, while Paranoid adds monkey input and deeper type mutation coverage.
