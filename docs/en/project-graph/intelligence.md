---
id: project-graph/intelligence
section: project-graph
slug: intelligence
title: Graph Intelligence
summary: How the graph scores relevance, traces flows and infers intent, and what the intelligence commands return.
order: 60
locale: en
status: canonical
keywords:
  - relevance
  - hotspot
  - event-trace
  - flow-trace
---

# Graph Intelligence

The intelligence layer adds semantic understanding on top of the raw code structure. It answers questions that structural analysis alone cannot.

## Layers of Understanding

```
Layer 0: Structure  — classes, methods, edges (what exists)
Layer 1: Semantics  — domains, flows, boundaries (what it means)
Layer 2: Dynamics   — execution paths, event flows (how it runs)
Layer 3: Intelligence — risk scores, hotspots, intent (what matters)
Layer 4: Documentation — auto-generated docs, linked references (why it exists)
```

## Domain Context

Every class is automatically linked to a business domain based on:
- Module name matching (16 known domain patterns)
- Namespace analysis
- Attribute hints

### Known Domains

| Domain | Keywords |
|--------|----------|
| Auth | auth, login, register, permission, capability, rbac |
| Billing | billing, invoice, payment, subscription, pricing |
| Inventory | inventory, stock, product, warehouse, sku |
| Ordering | order, cart, checkout, fulfillment, shipping |
| Notification | notification, email, sms, push, alert |
| Media | media, image, video, upload, storage, asset |
| Search | search, index, query, filter, facet |
| Analytics | analytics, metric, report, dashboard, tracking |
| User | user, profile, account, preference, avatar |
| Content | content, page, article, post, cms, block |
| Tenancy | tenant, organization, workspace, team |
| Workflow | workflow, process, approval, state, transition |
| Scheduler | schedule, cron, job, task, timer |
| Ledger | ledger, event, propagat, replay, sequence |
| Cache | cache, redis, ttl, invalidat |
| Locale | locale, language, translation, i18n, l10n |

### Query

```bash
bin/semitexa ai:review-graph:module Ordering --format=json
```

Response includes domain context with name, description, and criticality.

## Execution Flows

Execution flows trace request → handler → service → event chains.

### How They're Built

1. A payload access attribute (`#[AsPublicPayload]` and friends) defines an entry point (HTTP route)
2. `#[AsPayloadHandler]` links to the payload
3. The handler's dependencies form the flow steps
4. Events emitted by the handler extend the flow

### Flow Metadata

```json
{
  "name": "CheckoutFlow",
  "entry_point": "route:POST:/api/checkout",
  "steps": [
    {"order": 1, "node": "class:App\\Ordering\\CheckoutPayload", "role": "payload"},
    {"order": 2, "node": "class:App\\Ordering\\CheckoutHandler", "role": "handler"},
    {"order": 3, "node": "class:App\\Ordering\\PaymentService", "role": "service"}
  ],
  "events_emitted": ["class:App\\Ordering\\Event\\OrderCreated"],
  "storage_touches": ["orders", "payments"],
  "external_calls": ["stripe_api"]
}
```

### Query

```bash
bin/semitexa ai:review-graph:flow-trace CheckoutFlow --format=json
```

## Event Lifecycles

The most powerful intelligence feature — traces an event from emission through every consumer.

### What It Shows

| Aspect | Source |
|--------|--------|
| Emitters | `Emits` edges (incoming) |
| Sync listeners | `ListensTo` edges with `executionMode=sync` |
| Async listeners | `ListensTo` edges with `executionMode=async` |
| Queued listeners | `ListensTo` edges with `executionMode=queued` |
| NATS subject | `#[Propagated]` attribute analysis |
| JetStream stream | Subject metadata |
| Replay handlers | Consumer edges on the subject |
| DLQ path | Queue configuration analysis |
| Retry config | Handler metadata |
| Idempotency key | Event schema (default: `event_id`) |

### Query

```bash
bin/semitexa ai:review-graph:event-trace OrderCreated --format=json
```

## NATS Subject Extraction

Subjects are extracted from `#[Propagated]` attributes:

```php
#[Propagated(domain: 'ordering')]
class OrderCreated extends LedgerEvent { ... }
```

Generates:
- Subject pattern: `semitexa.events.{node}.ordering.order_created`
- Stream: `EVENTS`
- Edge: `OrderCreated` → `subject:semitexa.events.{node}.ordering.order_created`

Aggregate commands from `#[AsAggregateCommand]`:

```php
#[AsAggregateCommand(aggregateType: 'order', aggregateIdField: 'orderId')]
class CreateOrderCommand { ... }
```

Generates:
- Subject pattern: `semitexa.commands.order.{ownerNode}`
- Edge: `CreateOrderCommand` → `subject:semitexa.commands.order.{ownerNode}`

## Aggregate Boundaries

Extracted from `#[OwnedAggregate]` attributes:

```php
#[OwnedAggregate(type: 'order', idField: 'id', creates: OrderCreated::class)]
class OrderCreated extends LedgerEvent { ... }
```

Creates:
- Aggregate node: `aggregate:order`
- Edge: `OrderCreated` → `aggregate:order` (role: creation_event)

## Hotspot Detection

Identifies high-risk components based on:

| Factor | Weight |
|--------|--------|
| Class name suffix (Service, Handler, Manager, Facade, Kernel) | 0.10-0.25 |
| Parent class presence | 0.05 |
| Interface count (>2) | 0.10 |
| Trait count (>1) | 0.05 |
| Attribute hints (Payload, Handler, Service) | 0.10 |

### Risk Levels

| Score | Level |
|-------|-------|
| ≥ 0.8 | CRITICAL |
| ≥ 0.6 | HIGH |
| ≥ 0.4 | MEDIUM |
| < 0.4 | LOW |

### Query

```bash
bin/semitexa ai:review-graph:context "anything" --format=json
```

Hotspots relevant to the task are included in the response.

## Intent Inference

Auto-generates "why this exists" documentation for significant classes.

### How It Works

1. **Class name suffix** — matches against 11 patterns (Handler, Service, Repository, Entity, Listener, Mapper, Validator, Provider, Factory, Middleware, Phase)
2. **Attribute analysis** — the payload access attributes, `#[AsPayloadHandler]`, `#[AsEvent]`, `#[SatisfiesServiceContract]`
3. **Confidence scoring** — 0.5 (base) to 0.9 (attribute + name match)

### Generated Documentation

```json
{
  "purpose": "Orchestrates the checkout process, coordinating payment and inventory",
  "responsibilities": [
    "Process request payload",
    "Produce response",
    "Emit domain events"
  ],
  "inferred_from": ["class_name_suffix", "AsPayloadHandler_attribute"],
  "confidence": 0.9
}
```

## Documentation Gap Detection

Finds undocumented high-value nodes by scoring:

| Factor | Points |
|--------|--------|
| Public API (`App\Api\*`) | +30 |
| payload access attribute | +25 |
| `#[AsPayloadHandler]` attribute | +20 |
| `#[AsService]` attribute | +20 |
| Dependency count (×2, max 20) | +0-20 |
| Cross-module dependencies (max 15) | +0-15 |
| Structural complexity | +10 |

Nodes scoring > 20 without existing documentation are flagged as gaps.

## Safe Attribute Resolution

All extractors use `SafeAttributeResolver` trait to handle incomplete attribute definitions gracefully.

When `#[AsEventListener(event: SomeEvent::class)]` is missing the required `execution` parameter:
1. `newInstance()` fails → caught
2. Fallback to `getArguments()` to read raw values
3. Continue with defaults

This prevents graph generation from failing on code that has attribute bugs.

## AST Fallback

When a class cannot be autoloaded (e.g., missing `Composer\Plugin\PluginInterface`):
1. `@class_exists()` suppresses the error
2. Parser falls back to AST-only `ClassInfo::fromAst()`
3. Extracts interfaces, traits, parent class, properties from PHP-Parser nodes
4. Graph still captures structural information

This ensures the graph works even with incomplete runtime dependencies.
