---
id: project-graph/ai-integration
section: project-graph
slug: ai-integration
title: Using the Graph as AI Context
summary: Feeding graph slices to an assistant: what to ask for, what comes back, and how it beats grep.
order: 70
locale: en
status: canonical
keywords:
  - AI context
  - ai:review-graph:context
  - slice
---

# Using the Graph as AI Context

This document describes how AI agents should use the project graph to understand and modify a Semitexa codebase.

## Why Use the Graph On Demand

The graph is the right tool when a task needs structural understanding. It should not be a mandatory startup ritual for every edit.

| Without Graph | With Graph |
|--------------|------------|
| Read 20+ files to understand structure | One query shows the full structure |
| Guess which classes are related | Edges show exact relationships |
| Manually trace event flows | `event-trace` shows the full chain |
| Risk breaking unknown dependents | `impact` shows blast radius |
| Assume module boundaries | Graph shows actual cross-module edges |

## Standard Workflow

```
1. Start from the task
   bin/semitexa ai:task "<task description>"

2. Fetch task-scoped graph context if needed
   bin/semitexa ai:review-graph:context "<task>" --format=json

3. Refresh the graph only when graph-backed answers are stale or missing
   bin/semitexa ai:review-graph:generate --json
   bin/semitexa ai:review-graph:stats --json

4. Trace relevant flows/events
   bin/semitexa ai:review-graph:event-trace <Event> --format=json
   bin/semitexa ai:review-graph:flow-trace <Flow> --format=json

5. Check impact before changes
   bin/semitexa ai:review-graph:impact <Component> --json

6. Read specific files (now you know which ones)
   Read the files identified in steps 3-5
```

If `ai:task` is not available in the current install, apply the same workflow manually: begin from a one-line task statement and choose only the narrowest graph command that answers that task.

## JSON Output

Graph trace/context commands use `--format=json`. Review-graph maintenance and query commands use `--json`.

- `--format=json`: `ai:review-graph:context`, `ai:review-graph:event-trace`, `ai:review-graph:flow-trace`
- `--json`: `ai:review-graph:generate`, `ai:review-graph:stats`, `ai:review-graph:impact`, `ai:review-graph:query`, `ai:review-graph:capabilities`

### Example: event-trace JSON

```json
{
  "event": "App\\Ordering\\Event\\OrderCreated",
  "emitters": ["class:App\\Ordering\\CheckoutHandler"],
  "sync_listeners": ["class:App\\Notification\\OrderNotificationListener"],
  "async_listeners": ["class:App\\Fulfillment\\FulfillmentListener"],
  "queued_listeners": [
    {"class": "class:App\\Analytics\\OrderAnalyticsListener", "queue": "analytics"}
  ],
  "nats_subject": "semitexa.events.{node}.ordering.order_created",
  "jetstream": "EVENTS",
  "replay_handlers": ["class:App\\Fulfillment\\Replay\\FulfillmentReplayHandler"],
  "dlq_path": "semitexa.queue.fulfillment.failed",
  "retry_config": {"maxRetries": 3, "retryDelay": 5},
  "idempotency_key": "event_id"
}
```

### Example: impact JSON

```json
{
  "component": "App\\Ordering\\PaymentService",
  "risk_level": "HIGH",
  "risk_score": 18,
  "direct_dependents": [
    {"id": "class:App\\Ordering\\CheckoutHandler", "type": "handler", "edge_type": "injects_readonly"},
    {"id": "class:App\\Ordering\\RefundHandler", "type": "handler", "edge_type": "injects_readonly"}
  ],
  "transitive_dependents": [
    {"id": "class:App\\Api\\CheckoutPayload", "type": "payload", "depth": 2}
  ],
  "cross_module_impact": {
    "Billing": 2,
    "Notification": 1
  },
  "event_impact": [
    {"event": "App\\Ordering\\Event\\PaymentProcessed", "listener_count": 3}
  ],
  "blast_radius": 6
}
```

## Query Patterns

### "What does X do?"

```bash
bin/semitexa ai:review-graph:show class:App\\Ordering\\CheckoutHandler --depth=2 --json
```

Shows the node's type, metadata, and all connections.

### "How does X work?"

```bash
bin/semitexa ai:review-graph:flow-trace CheckoutFlow --format=json
```

Shows the full execution flow with ordered steps.

### "What happens when X is emitted?"

```bash
bin/semitexa ai:review-graph:event-trace OrderCreated --format=json
```

Shows the complete event lifecycle.

### "What breaks if I change X?"

```bash
bin/semitexa ai:review-graph:impact PaymentService --json
```

Shows all dependents with risk scoring.

### "What's relevant to my task?"

```bash
bin/semitexa ai:review-graph:context "adding Stripe payment" --format=json
```

Shows matched components, flows, events, dependencies, and hotspots.

### "What's in this module?"

```bash
bin/semitexa ai:review-graph:module Ordering --include-events --include-flows --format=json
```

Shows everything in a module with context.

### "Find all X in module Y"

```bash
bin/semitexa ai:review-graph:query handler --module=Ordering --json
```

Returns all nodes of a type in a module.

## Node ID Resolution

When a command accepts a component name, it tries these strategies in order:

1. Exact node ID match (`class:App\...`)
2. FQCN match (`App\Ordering\CheckoutHandler`)
3. Partial FQCN match (`CheckoutHandler`)
4. Short name search (`Checkout`)

## Intelligence Layer

The graph includes computed intelligence beyond raw structure:

### Domain Context

Every class is linked to a business domain:

```bash
bin/semitexa ai:review-graph:module Ordering --format=json
```

Response includes:
```json
{
  "domain_context": {
    "name": "Ordering",
    "description": "Manages Ordering domain with request handlers and event-driven flows",
    "criticality": "high"
  }
}
```

### Hotspots

High-risk components are identified by:
- Number of incoming dependencies
- Cross-module dependency count
- Structural complexity
- Critical path membership

```bash
bin/semitexa ai:review-graph:context "anything" --format=json
```

Response includes hotspots relevant to the task.

### Intent Inference

Every significant class has auto-generated documentation:
- Purpose (one-line description)
- Responsibilities (what it does)
- Inferred from (how the inference was made)
- Confidence score

## Watch Mode

During active development, keep the graph up to date:

```bash
bin/semitexa ai:review-graph:watch --interval=2
```

This polls for file changes every 2 seconds and incrementally updates the graph.

## Error Handling

If a command returns no results:

1. Refresh the graph with `generate` if the answer depends on fresh structure
2. Run `stats` to verify the graph has data
3. Try a broader search term or use `query` to list available nodes

If a file has parsing errors (e.g., missing dependencies), the graph still processes it — the parser falls back to AST-only mode when reflection fails.
