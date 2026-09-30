---
id: project-graph/findings
section: project-graph
slug: findings
title: Unused Classes and Dependency Loops
summary: ai:review-graph:findings lists classes nothing depends on, graded by how sure that is, and the class dependency loops in the project — each row with the reason.
order: 37
locale: en
status: canonical
verified_against: 2026.09.30.1034
keywords:
  - ai:review-graph:findings
  - unused classes
  - dead code
  - dependency cycles
related_documents:
  - project-graph/coverage
  - project-graph/commands
---
# Unused Classes and Dependency Loops

```bash
bin/semitexa ai:review-graph:findings
bin/semitexa ai:review-graph:findings --kind=unused --min-confidence=high
bin/semitexa ai:review-graph:findings --kind=cycles --module=Orm --format=markdown
```

Answered from the project graph in well under a second. Every finding has a stable id (its kind and a hash of its classes), so two runs can be compared line by line, and every format carries the [coverage](coverage.md) summary.

## Unused classes

A class counts as **used** when a declared class outside `tests/` depends on it — a code reference (extends, implements, `new`, a type, a static call, a constant, `instanceof`, `catch`, an attribute) or wiring (injection, `handles`, `listens_to`, ...). What is left is graded:

| Confidence | Meaning |
|---|---|
| `high` | Nothing refers to it, the framework does not discover it, and no runtime class name in its module could reach it. |
| `medium` | Nothing refers to it, but the framework discovers it — its role (command, handler, listener, service, ...), wiring it declares, or a Semitexa attribute on the class (`#[AsAiSkill]`, `#[AsMapper]`, `#[Capability]`) — or only tests use it. |
| `low` | It is named only as `Foo::class`, or its module builds class names at runtime (`new $class`), so it may be reached in a way the graph cannot see. |

Test code is never a candidate; `#[GraphIgnore]` classes are skipped. The evidence line says which rule applied.

**Read before deleting.** A `high` finding is a class this workspace does not use. A package may still offer it to its consumers — a test double documented in the README, say — and that is a decision for a person, not for the report. Classes referenced only from configuration files (a PHPStan rule registered in `phpstan.neon`) are not seen yet and show up as "used only by tests" or `high`.

## Dependency loops

Classes that depend on each other in a loop, one row per loop: its members and one shortest cycle through them — the concrete path to read and break. Only real dependencies count (code references and injection); a `use` line alone, an attribute, and inferred edges do not.

```
15 classes: ContainerFactory -> OrmManager -> StaticLoggerBridge -> ContainerFactory
2 classes: CacheManager -> ScopedCacheManager -> CacheManager
```

## Options

| Option | Description |
|---|---|
| `--kind=unused\|cycles\|all` | What to report (default `all`) |
| `--min-confidence=high\|medium\|low` | Unused classes at or above this grade (default `medium`) |
| `--module=NAME` | Unused classes of one module, and loops that touch it |
| `--format=text\|json\|ndjson\|markdown` | `ndjson` starts with a summary line |
| `--no-refresh` | Answer from the graph as it is, without the incremental refresh |
