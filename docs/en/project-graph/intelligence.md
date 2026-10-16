---
id: project-graph/intelligence
section: project-graph
slug: intelligence
title: Graph Intelligence
summary: How the graph scores relevance, traces flows and infers intent, and what the intelligence commands return.
order: 60
locale: en
status: canonical
verified_against: 2026.09.19.1020
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

Execution flows trace request → handler → storage → event → listener chains.

### How They're Built

The graph stores only the flow node: one per payload, with the id `flow:<payload class>` and a readable `name` (`CheckoutPayload` → `CheckoutFlow`). Two modules can each hold a `CheckoutFlow`; their ids differ.

Everything else is walked out of the stored edges on each query, never stored. A stored edge belongs to the file that produced it; a handler → event → listener edge spans three files and would go stale when one of them changed.

1. A payload access attribute (`#[AsPublicPayload]` and friends) defines the entry point (HTTP route)
2. `#[AsPayloadHandler]` adds the handler
3. From the handler the walk follows injections, references and instantiations through services, up to 6 hops, and stops at an entity: its `#[FromTable]` table is a storage touch
4. An injected interface is crossed only when one class can be behind it: the one bound with `#[SatisfiesServiceContract]` / `#[SatisfiesRepositoryContract]`, or else its only implementation. As in the container, a Core binding loses to a module's
5. A class imported by 5 or more other modules (`OrmManager`, `Environment`, the tenant context) is framework infrastructure: it is listed under `infrastructure` and walked for the events it emits, but its own tables and gaps are not the flow's
6. Every event emitted on the way adds its listeners as steps, and the walk continues from each listener the flow's own module triggers
7. The first async handler or listener is the sync boundary

### Reachability, not execution

The graph holds classes and what they inject, reference and emit; it holds no method calls. A flow's tables and events are therefore what its classes **can reach**, not what one request does: a handler that only reads, through a service that can also write, is credited with the write and the events it fires. Checked by hand on the Tasks module: the mutating flow was exact, the two read-only flows were each given a table and an ORM event that only a save causes.

Read `storage_touches` as "tables it can reach" and `events_emitted` as "events it may fire". The steps, the listeners and the gaps are what the graph actually holds.

### Flow Shape

```json
{
  "id": "flow:App\\Ordering\\CheckoutPayload",
  "flow": "CheckoutFlow",
  "entry_point": "route:POST:/api/checkout",
  "steps": [
    {"order": 1, "node": "App\\Ordering\\CheckoutPayload", "role": "payload"},
    {"order": 2, "node": "App\\Ordering\\CheckoutHandler", "role": "handler"},
    {"order": 3, "node": "App\\Ordering\\SendReceiptListener", "role": "listener",
     "event": "App\\Ordering\\Event\\OrderCreated", "execution": "async", "certain": true}
  ],
  "sync_boundary": 2,
  "events_emitted": ["App\\Ordering\\Event\\OrderCreated"],
  "storage_touches": ["orders", "payments"],
  "external_calls": [],
  "infrastructure": ["Semitexa\\Orm\\OrmManager"],
  "complete": false,
  "gaps": [
    {"kind": "ambiguous_contract", "at": "class:App\\Ordering\\PaymentGatewayInterface",
     "detail": "2 classes implement it and none is bound with #[SatisfiesServiceContract] or #[SatisfiesRepositoryContract]",
     "candidates": ["App\\Ordering\\StripeGateway", "App\\Ordering\\FakeGateway"]}
  ]
}
```

A listener is a step because the graph saw it subscribe, not because it acts on every event. When the event comes from another module than the flow's handler — the ORM's `ResourceChangedEvent`, fired on every write — the step carries `"certain": false` and the walk does not enter the listener: it decides at runtime whether this flow concerns it.

### Gaps

Wherever the walk cannot vouch for what comes next it records a gap instead of going quiet. A flow is `complete` only with no gaps.

| Gap | Meaning |
|-----|---------|
| `no_handler` | No `#[AsPayloadHandler]` declares the payload and it names no `responseWith:` (with one, the response is the flow's last step) |
| `ambiguous_contract` | Two or more classes can stand behind an injected interface; `candidates` names them |
| `unbound_contract` | A project interface is injected and nothing implements it |
| `depth_limit` | The walk stopped at 6 hops with classes still left to follow |
| `dynamic_reference` | A class on the way names a class only at runtime (`new $class`, `$class::make()`) |
| `event_without_listener` | An event is emitted and no listener of it is in the graph |
| `conditional_listener` | A listener of another module's event; it may or may not act for this flow |

Across every flow at once: `ai:review-graph:findings --kind=flows` — one finding per place, weighted by how many flows it blinds.

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
