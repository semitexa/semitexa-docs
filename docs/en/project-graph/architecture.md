---
id: project-graph/architecture
section: project-graph
slug: architecture
title: Project Graph Architecture
summary: How the graph is built: parsers, the extractor pipeline, node and edge types, and which attribute produces which node.
order: 40
locale: en
status: canonical
keywords:
  - extractor
  - NodeType
  - EdgeType
  - ExtractorPipeline
---

# Project Graph Architecture

The project graph is a structured representation of a Semitexa codebase, built by scanning PHP source files, extracting semantic information via attributes and AST analysis, and storing it as a directed graph of nodes and edges.

## Data Model

### Nodes

Every entity in the codebase becomes a node with a unique ID, type, and metadata.

#### Node ID Format

Nodes use prefixed IDs for fast type identification:

| Prefix | Example |
|--------|---------|
| `class:` | `class:App\Ordering\CheckoutHandler` |
| `method:` | `method:App\Ordering\CheckoutHandler::handle` |
| `prop:` | `prop:App\Ordering\CheckoutHandler::$paymentService` |
| `route:` | `route:POST:/api/checkout` |
| `module:` | `module:Ordering` |
| `file:` | `file:src/Ordering/CheckoutHandler.php` |
| `ns:` | `ns:App\Ordering` |
| `domain:` | `domain:Ordering` |
| `flow:` | `flow:CheckoutFlow` |
| `event_flow:` | `event_flow:OrderFulfillmentFlow` |
| `lifecycle:` | `lifecycle:ProductLifecycle` |
| `boundary:` | `boundary:OrderingBoundary` |
| `hotspot:` | `hotspot:App\Ordering\CheckoutHandler` |
| `stream:` | `stream:EVENTS` |
| `subject:` | `subject:semitexa.events.{node}.ordering.order_created` |
| `consumer:` | `consumer:node-ordering` |
| `schema:` | `schema:App\Ordering\Event\OrderCreated` |
| `aggregate:` | `aggregate:order` |
| `replay:` | `replay:OrderFulfillmentReplay` |
| `doc:` | `doc:class:App\Ordering\CheckoutHandler` |
| `example:` | `example:class:App\Ordering\CheckoutHandler:basic` |
| `adr:` | `adr:001-event-sourcing` |

#### Node Types

**Structural (from code):**

| Type | Description |
|------|-------------|
| `file` | PHP source file |
| `namespace` | PHP namespace |
| `module` | Semitexa module |
| `class` | PHP class |
| `interface` | PHP interface |
| `trait` | PHP trait |
| `enum` | PHP enum |
| `method` | Class method |
| `property` | Class property |
| `constant` | Class constant |
| `enum_case` | Enum case |

**Semantic (from attributes):**

| Type | Attribute | Description |
|------|-----------|-------------|
| `payload` | `#[AsPublicPayload]`, `#[AsProtectedPayload]`, `#[AsServicePayload]` | HTTP request payload |
| `handler` | `#[AsPayloadHandler]` | HTTP request handler |
| `resource` | `#[AsResourcePart]` | API resource component |
| `service` | `#[AsService]` | Business service |
| `event_listener` | `#[AsEventListener]` | Event listener |
| `event` | `#[AsEvent]` | Domain event |
| `command` | `#[AsCommand]` | CLI command |
| `component` | `#[AsComponent]` | UI component |
| `entity` | `#[FromTable]` | Domain entity |
| `repository` | `#[AsRepository]` | Data repository |
| `job` | `#[AsScheduledJob]` | Background job |
| `ai_skill` | `#[AsAiSkill]` | AI skill definition |
| `contract` | `#[SatisfiesServiceContract]` | Service contract implementation |
| `route` | (derived from payload) | HTTP route |
| `pipeline_phase` | `#[AsPipelineListener]`, `#[AsServerLifecycleListener]` | Pipeline phase |
| `slot_handler` | `#[AsSlotHandler]` | Template slot handler |
| `auth_handler` | `#[AsAuthHandler]` | Authentication handler |
| `data_provider` | `#[AsDataProvider]` | Data provider |

**Intelligence layer (computed):**

| Type | Description |
|------|-------------|
| `domain_context` | Business domain (e.g. "Billing", "Inventory") |
| `execution_flow` | Named request flow (e.g. "CheckoutFlow") |
| `event_flow` | Named event chain |
| `data_lifecycle` | Entity lifecycle |
| `system_boundary` | Module isolation boundary |
| `hotspot` | High-risk / frequently-used area |
| `jetstream` | NATS JetStream definition |
| `nats_subject` | NATS subject pattern |
| `consumer` | NATS consumer definition |
| `event_schema` | Event schema definition |
| `aggregate_root` | DDD aggregate boundary |
| `replay_path` | Cross-node replay flow |
| `doc_node` | Auto-generated documentation |
| `usage_example` | Code example |
| `architectural_decision` | ADR |

### Edges

Edges represent relationships between nodes.

#### Structural Edges

| Edge Type | Source → Target | Meaning |
|-----------|----------------|---------|
| `extends` | class → class | Class inheritance |
| `implements` | class → interface | Interface implementation |
| `uses` | class → trait | Trait usage |
| `imports` | file → class | Use statement |
| `calls` | method → method | Method call |
| `instantiates` | class → class | Object creation |
| `returns` | method → class | Return type |
| `accepts` | method → class | Parameter type |
| `defined_in` | method/prop → class | Member of class |
| `in_file` | class → file | Class defined in file |
| `in_module` | class → module | Class belongs to module |
| `in_namespace` | class → namespace | Class in namespace |

#### Semantic Edges

| Edge Type | Source → Target | Meaning |
|-----------|----------------|---------|
| `handles` | handler → payload | Handler processes payload |
| `produces` | handler → resource | Handler produces resource |
| `serves_route` | payload → route | Payload serves route |
| `injects_readonly` | class → service | Readonly dependency injection |
| `injects_mutable` | class → service | Mutable dependency injection |
| `injects_factory` | class → factory | Factory injection |
| `injects_config` | class → config | Config injection |
| `listens_to` | listener → event | Event listener |
| `emits` | class → event | Event emission |
| `satisfies_contract` | class → contract | Contract implementation |
| `requires_permission` | payload → permission | Permission requirement |
| `requires_capability` | payload → capability | Capability requirement |
| `tenant_isolated` | class → module | Tenant isolation |
| `pipeline_phase` | phase → pipeline | Pipeline membership |
| `renders_slot` | component → slot | Slot rendering |
| `provides_data` | provider → component | Data provision |
| `maps_to_table` | entity → table | ORM mapping |
| `has_relation` | entity → entity | ORM relation |
| `exposes_api` | module → endpoint | API exposure |
| `scheduled_as` | job → schedule | Job scheduling |
| `composed_of` | class → trait/composition | Composition |
| `extends_module` | module → module | Module extension |
| `authenticates` | handler → auth | Authentication |
| `tests` | test → class | Test relationship |

#### Intelligence Edges

| Edge Type | Source → Target | Meaning |
|-----------|----------------|---------|
| `belongs_to_domain` | class → domain | Domain membership |
| `participates_in_flow` | class → flow | Flow participation |
| `triggers_flow` | class → flow | Flow trigger |
| `precedes_in_flow` | class → class | Flow ordering |
| `crosses_boundary` | class → boundary | Boundary crossing |
| `is_hotspot` | class → hotspot | Risk indicator |
| `coupled_to` | class → class | Bidirectional coupling |
| `intent_for` | class → doc | Intent documentation |
| `publishes_to` | class → subject | NATS publication |
| `consumes_from` | consumer → subject | NATS consumption |
| `streams_to` | class → stream | JetStream production |
| `has_schema` | event → schema | Event schema |
| `is_aggregate_of` | event → aggregate | Aggregate membership |
| `replays_via` | event → replay | Replay path |
| `routes_command_to` | command → subject | Command routing |
| `dead_letters_to` | queue → subject | DLQ routing |
| `retries_via` | handler → config | Retry configuration |
| `documented_by` | class → doc | Documentation link |
| `has_example` | class → example | Usage example |
| `references_adr` | class → adr | Architecture decision |
| `supersedes` | doc → doc | Documentation versioning |

## Extractors

### Attribute-Based Extractors

These extractors scan PHP attributes and create semantic nodes/edges.

| Extractor | Attributes | Creates |
|-----------|-----------|---------|
| `PayloadExtractor` | `#[AsPublicPayload]`, `#[AsProtectedPayload]`, `#[AsServicePayload]`, `#[RequiresPermission]`, `#[RequiresCapability]` | Payload nodes, route nodes, permission/capability edges |
| `HandlerExtractor` | `#[AsPayloadHandler]` | Handler nodes, handles/produces edges |
| `ServiceExtractor` | `#[AsService]`, `#[SatisfiesServiceContract]` | Service nodes, contract edges |
| `EventExtractor` | `#[AsEvent]`, `#[AsEventListener]` | Event nodes, listener nodes, listens_to edges |
| `InjectionExtractor` | `#[InjectAsReadonly]`, `#[InjectAsMutable]`, `#[InjectAsFactory]`, `#[Config]` | Injection edges |
| `OrmExtractor` | `#[FromTable]`, `#[BelongsTo]`, `#[HasMany]`, `#[ManyToMany]`, `#[OneToOne]`, `#[SoftDelete]`, `#[TenantScoped]`, `#[AsMapper]`, `#[AsRepository]` | Entity nodes, relation edges, table mappings |
| `AuthExtractor` | `#[RequiresPermission]`, `#[RequiresCapability]`, `#[AuthLevel]` | Permission/capability nodes, auth edges |
| `DomainContextExtractor` | (inferred from module names) | Domain context nodes, belongs_to_domain edges |
| `ExecutionFlowExtractor` | the payload access attributes, `#[AsPayloadHandler]` | Execution flow nodes, participates_in_flow edges |
| `NatsSubjectExtractor` | `#[Propagated]`, `#[OwnedAggregate]`, `#[AsAggregateCommand]` | NATS subject nodes, aggregate nodes, publishes_to/consumes_from edges |
| `IntentInferenceExtractor` | (inferred from class names + attributes) | Doc nodes with purpose/responsibilities |
| `HotspotExtractor` | (computed from structural analysis) | Hotspot nodes with risk scores |

### AST-Based Extractors

These extractors analyze the PHP AST directly.

| Extractor | Creates |
|-----------|---------|
| `InheritanceExtractor` | extends, implements edges |
| `TraitUseExtractor` | uses edges |
| `MethodCallExtractor` | calls, instantiates edges |
| `InstantiationExtractor` | instantiates edges |
| `TypeHintExtractor` | returns, accepts edges |
| `UseStatementExtractor` | imports edges |

## Storage

The graph uses a dedicated SQLite database with a named ORM connection (`project_graph`).

### Tables

| Table | Purpose |
|-------|---------|
| `graph_nodes` | All nodes (id, type, fqcn, file, line, end_line, module, metadata) |
| `graph_edges` | All edges (source_id, target_id, type, metadata) |
| `graph_meta` | Key-value metadata (last scan timestamps, stats) |
| `graph_file_index` | File tracking for incremental updates (path, module, hash, scanned_at) |

### Configuration

```env
DB_PROJECT_GRAPH_DRIVER=sqlite
DB_PROJECT_GRAPH_DATABASE=var/tmp/project-graph.sqlite
```

## Incremental Updates

The graph supports incremental updates via `IncrementalEngine`:

1. File scanner detects changed/added/removed files
2. Changed files are re-parsed and re-extracted
3. Old nodes/edges from removed files are deleted
4. New nodes/edges are inserted
5. File index is updated with new hashes

This makes subsequent runs fast — only changed files are processed.
