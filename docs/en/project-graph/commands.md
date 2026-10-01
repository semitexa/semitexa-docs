---
id: project-graph/commands
section: project-graph
slug: commands
title: Project Graph Command Reference
summary: Every ai:review-graph command with its options, output shape and a worked example.
order: 50
locale: en
status: canonical
verified_against: 2026.09.30.1034
keywords:
  - ai:review-graph
  - generate
  - query
  - impact
  - watch
  - findings
---

# Project Graph Command Reference

All commands are prefixed with `ai:review-graph:` and are available via `bin/semitexa`.

## Core Commands

### generate

```bash
bin/semitexa ai:review-graph:generate
```

Scans the codebase and builds (or incrementally updates) the project graph.

**Options:**

| Option | Description |
|--------|-------------|
| `--full` | Force full rebuild (ignore incremental cache) |
| `--module=NAME` | Scan only a specific module |
| `--json` | Output results as JSON |

**Output:**

```
Incremental update complete. 8 files scanned, +15/-5 nodes, +132/-0 edges. (209ms)
```

**When to use:** Before any code review, refactoring, or feature addition. Always run this first.

---

### stats

```bash
bin/semitexa ai:review-graph:stats
```

Shows graph statistics — node counts by type, edge counts, module breakdown.

**Options:**

| Option | Description |
|--------|-------------|
| `--module=NAME` | Stats for specific module |
| `--json` | Output as JSON |

**Output:**

```
Graph Statistics:
  Total nodes: 1,247
  Total edges: 3,891
  Modules: 12
  Files indexed: 487
```

**When to use:** Verify the graph is populated and complete before querying.

---

### show

```bash
bin/semitexa ai:review-graph:show
bin/semitexa ai:review-graph:show 'App\Ordering\CheckoutHandler' --depth=2 --format=markdown
bin/semitexa ai:review-graph:show --module=Ordering --format=html --output=var/tmp/ordering.html
```

Render the graph, or a slice of it, as text or as a self-contained HTML viewer.

**Arguments:**

| Argument | Description |
|----------|-------------|
| `focus` | Optional. The node to centre the slice on: an FQCN, a file path or a module name |

**Options:**

| Option | Description |
|--------|-------------|
| `--format=summary\|json\|dot\|markdown\|html` | Output format (default: `summary`). An unknown format is an error |
| `--output=FILE` | File to write; required for `html`, which writes the [graph viewer](viewer.md) with the slice embedded |
| `--module=NAME` | Only nodes of this module |
| `--type=T1,T2` | Only nodes of these types |
| `--depth=N` | Steps from the focus node (default: 3) |

**When to use:** Look at a slice of the graph, or hand one to a reviewer as a file that opens without a running stack.

---

### query

```bash
bin/semitexa ai:review-graph:query <type> [--module=NAME] [--filter=KEY:VALUE]
```

Find nodes by type, module, or metadata filters.

**Arguments:**

| Argument | Description |
|----------|-------------|
| `type` | Node type to search (e.g. `handler`, `event`, `service`) |

**Options:**

| Option | Description |
|--------|-------------|
| `--module=NAME` | Filter by module |
| `--filter=KEY:VALUE` | Filter by metadata key-value |
| `--json` | Output as JSON |

**Examples:**

```bash
# Find all handlers
bin/semitexa ai:review-graph:query handler

# Find all events in Ordering module
bin/semitexa ai:review-graph:query event --module=Ordering

# Find all payloads with a specific path
bin/semitexa ai:review-graph:query payload --filter=path:/api/checkout
```

---

## Intelligence Commands

### event-trace

```bash
bin/semitexa ai:review-graph:event-trace <event>
```

Trace the full lifecycle of an event from emission through all consumers.

**Arguments:**

| Argument | Description |
|----------|-------------|
| `event` | Event class name (short or FQCN) |

**Options:**

| Option | Description |
|--------|-------------|
| `--format=text\|json\|markdown` | Output format (default: text) |
| `--include-code` | Include source file paths |

**Shows:**
- Who emits the event
- Sync listeners (inline)
- Async listeners (Swoole defer)
- Queued listeners (with queue names)
- NATS subject and JetStream stream
- Replay handlers
- DLQ path and retry config
- Idempotency key

**Examples:**

```bash
bin/semitexa ai:review-graph:event-trace OrderCreated
bin/semitexa ai:review-graph:event-trace OrderCreated --format=json
bin/semitexa ai:review-graph:event-trace OrderCreated --include-code
```

**When to use:** Understanding event-driven flows, debugging event propagation, finding all consumers of an event.

---

### flow-trace

```bash
bin/semitexa ai:review-graph:flow-trace <flow>
```

Trace an execution flow end-to-end — from entry point through all steps.

**Arguments:**

| Argument | Description |
|----------|-------------|
| `flow` | Flow name or payload class |

**Options:**

| Option | Description |
|--------|-------------|
| `--format=text\|json\|markdown` | Output format (default: text) |
| `--include-code` | Include source file paths |

**Shows:**
- Entry point (route)
- Ordered steps with roles
- Sync/async boundary
- Events emitted
- Storage touches
- External calls

**Examples:**

```bash
bin/semitexa ai:review-graph:flow-trace CheckoutFlow
bin/semitexa ai:review-graph:flow-trace CheckoutPayload --format=json
```

**When to use:** Understanding how a request flows through the system, onboarding to a new module.

---

### context

```bash
bin/semitexa ai:review-graph:context "<task>"
```

Build relevant context for a development task — matched components, related flows, events, dependencies, hotspots.

**Arguments:**

| Argument | Description |
|----------|-------------|
| `task` | Natural language description of what you're working on |

**Options:**

| Option | Description |
|--------|-------------|
| `--format=text\|json` | Output format (default: text) |
| `--depth=N` | Context depth 1-3 (default: 2) |
| `--module=NAME` | Limit to specific module |

**Examples:**

```bash
bin/semitexa ai:review-graph:context "adding payment method"
bin/semitexa ai:review-graph:context "fixing checkout bug" --format=json
bin/semitexa ai:review-graph:context "user authentication" --module=Authorization
```

**When to use:** Before starting any task — gives you the full picture of what's relevant.

---

### impact

```bash
bin/semitexa ai:review-graph:impact <component>
```

Analyze the impact of changing a component — direct/transitive dependents, cross-module impact, blast radius.

**Arguments:**

| Argument | Description |
|----------|-------------|
| `component` | Component to analyze (class name, module, or file) |

**Options:**

| Option | Description |
|--------|-------------|
| `--format=text\|json` | Output format (default: text) |
| `--depth=N` | Traversal depth 1-3 (default: 2) |

**Shows:**
- Risk level (CRITICAL/HIGH/MEDIUM/LOW) and score
- Direct dependents (with edge types)
- Transitive dependents (with depth)
- Cross-module impact (by module)
- Event impact (events emitted + listener counts)
- Total blast radius

**Examples:**

```bash
bin/semitexa ai:review-graph:impact PaymentService
bin/semitexa ai:review-graph:impact CheckoutHandler --depth=3 --format=json
```

**When to use:** Before making changes to shared services, handlers, or events.

---

### module

```bash
bin/semitexa ai:review-graph:module <name>
```

Overview of a module with full context — summary, domain, flows, events, hotspots, cross-module deps.

**Arguments:**

| Argument | Description |
|----------|-------------|
| `module` | Module name |

**Options:**

| Option | Description |
|--------|-------------|
| `--format=text\|json` | Output format (default: text) |
| `--include-events` | Include event details |
| `--include-flows` | Include execution flows |

**Shows:**
- Component counts (classes, events, handlers, services, routes)
- Domain context (name, description, criticality)
- Execution flows with steps
- Events with listeners and NATS subjects
- Hotspots with risk scores
- Cross-module dependencies

**Examples:**

```bash
bin/semitexa ai:review-graph:module Ordering
bin/semitexa ai:review-graph:module Ordering --include-events --include-flows --format=json
```

**When to use:** Onboarding to a new module, reviewing module boundaries.

---

### diff

```bash
bin/semitexa ai:review-graph:diff --base=origin/develop --path=packages/semitexa-orm
bin/semitexa ai:review-graph:diff --base=origin/develop --format=markdown --fail-on=orphaned-removals
bin/semitexa ai:review-graph:diff
```

With `--base=<git ref>`: the structural difference between the working tree and that ref, edge by edge. Both graphs are built fresh — the working tree, and a detached worktree of the ref that is removed afterwards — by the same code over the same directory; the project graph is neither read nor changed. An edge is identified by its type, source and target, so a line diff that moves code around without changing what depends on what shows nothing here, and a changed `#[AsPublicPayload]` path shows as the route dropped and the route served now.

Without `--base`: node and edge counts since the previous run, kept in the graph.

**Options:**

| Option | Description |
|--------|-------------|
| `--base=REF` | Git ref to compare the working tree with (e.g. `origin/develop`, `HEAD~1`) |
| `--path=DIR` | With `--base`: the directory to compare, inside a git repository (default: the project root). In a workspace of package repositories, the package: `--path=packages/semitexa-orm` |
| `--format=text\|json\|markdown` | With `--base`: `markdown` is a pull-request comment (without `--base` it falls back to text): wiring changes first (routes, handlers, listeners, injection, contracts), then code references; imports and inferred edges folded into one count line |
| `--fail-on=orphaned-removals` | With `--base` (ignored without it): exit non-zero when a removal leaves something pointing at nothing (below), or when the head has files the graph could not parse |
| `--module=NAME` | Without `--base`: limit the counts to a module |

**Orphaned removals.** Removing a route, a handler or a listener is often the point of a change and passes. The gate fails only when the head still relies on what went away:

| Kind | Meaning |
|---|---|
| `unhandled_payload` | a payload still serves a route but lost its handler |
| `unsatisfied_contract` | a contract is still injected but lost its only implementation |
| `unemitted_event` | an event is still listened to but nobody emits it |
| `unserved_route` | something points at a route nothing serves |
| `deleted_class_still_referenced` | a declared class is gone while declared classes still reference it (a rename that missed a caller) |

There is no rule about mentioning a change in the pull-request description: the gate checks the code. A file the graph could not parse makes the gate fail rather than pass — it cannot vouch for code it did not read. Edges the graph could not see at all (runtime class names) are not in the diff; the markdown comment says so when there are any (see [coverage](coverage.md)).

**When to use:** In review, to see the structural change a line diff hides; in CI, as a gate on removals.

---

### findings

```bash
bin/semitexa ai:review-graph:findings --min-confidence=high
```

Unused classes graded by confidence, and class dependency loops, each with the reason. Options and grading rules: [Unused Classes and Dependency Loops](findings.md).

---

## Utility Commands

### capabilities

```bash
bin/semitexa ai:review-graph:capabilities
```

List all available commands and their capabilities.

### watch

```bash
bin/semitexa ai:review-graph:watch [--interval=2] [--full-on-start]
```

Watch for file changes and incrementally update the graph in real-time.

**Options:**

| Option | Description |
|--------|-------------|
| `--interval=N` | Polling interval in seconds (default: 2) |
| `--full-on-start` | Run full build before watching |

**When to use:** During active development — keeps the graph always up to date.

---

## JSON Output

All commands support `--format=json` (or `--json` for older commands). This is the primary interface for AI agents.

```bash
bin/semitexa ai:review-graph:event-trace OrderCreated --format=json
```

Returns structured data that can be parsed and used programmatically.
