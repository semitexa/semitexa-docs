---
id: cli/scaffolding-generators
section: cli
slug: scaffolding-generators
title: Scaffolding Generators
summary: Scaffold modules, pages, payloads, services, and contracts through commands that already understand Semitexa structure and AI-friendly output modes.
order: 30
locale: en
status: canonical
keywords:
  - make:module
  - make:page
  - make:payload
  - make:service
  - make:contract
  - --llm-hints
---
# Scaffolding Generators

The generator surface matters because it teaches the framework shape by producing the right files, not by asking the developer to remember ceremony.

## How it works

`make:module`, `make:page`, `make:payload`, `make:service`, `make:contract`, `make:handler`, `make:resource`, `make:command`, and `make:event-listener` each encode Semitexa's expected file layout and attribute conventions directly into the generated stubs. Dry-run and `--llm-hints` modes let humans and agents inspect the plan before files are written. JSON output makes generator results consumable by automated workflows.

## Payload access modifiers

Both `make:payload` and `make:page` accept `--access=public|protected|service`. The flag picks exactly one of the three current access attributes:

| `--access` value | Generated attribute | When to use |
|---|---|---|
| `public` (explicit) | `#[AsPublicPayload]` | Anonymous endpoints — login, marketing pages, health checks. |
| `protected` (default) | `#[AsProtectedPayload]` | User-authenticated endpoints — the safe default. |
| `service` | `#[AsServicePayload]` | Machine-to-machine endpoints — webhook receivers, partner integrations, internal service callers. |

The default is `protected` so an omitted flag still produces a closed-by-default endpoint. An invalid value (`--access=open`, `--access=foo`) fails the generator with a clear `Unknown payload access type` error before any file is written. The three access attributes are mutually exclusive in generated output — `GeneratorForbiddenPatternRegressionTest::payload_plan_builder_emits_exactly_one_access_attribute` pins the contract.

## Why this matters

The point is not fewer keystrokes. The point is fewer incorrect architectural starts. Good scaffolding shortens onboarding because the produced files demonstrate the intended structure directly and reduce wrong patterns before they become habits. The framework's regression suite scans every template and every plan builder output for retired payload attributes (see the [post-hardening migration guide](../migration/post-hardening.md) for the full list), so a future template tweak that silently reintroduces a stale shape fails CI before it lands.
