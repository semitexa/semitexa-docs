---
id: testing/payload-contracts
section: testing
slug: payload-contracts
title: Payload Contract Testing
summary: Run one project-level contract suite through the canonical test runner and let strategy profiles verify payload boundaries without hand-writing repetitive negative cases.
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

The testing framework discovers testable payloads, applies strategy profiles (Standard, Strict, Paranoid), and runs security, type enforcement, and monkey testing strategies against each endpoint. The semitexa-testing package ships its own `ProjectPayloadsContractTest` integration test, and the canonical runner `bin/semitexa test:run` appends that suite automatically from `vendor/semitexa/testing/tests/Integration/ProjectPayloadsContractTest.php` in consuming projects. That keeps payload-contract coverage enabled without scaffolding a root-level `tests/` bucket, but it also means direct `vendor/bin/phpunit` runs are not the supported entry point.

## Why this matters

Contract tests verify that payloads reject bad input and accept good input without writing individual test cases. Strategy profiles let teams choose their risk tolerance -- Strict catches common boundary mistakes, while Paranoid adds monkey input and deeper type mutation coverage.
