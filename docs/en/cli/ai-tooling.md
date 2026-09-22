---
id: cli/ai-tooling
section: cli
slug: ai-tooling
title: Semitexa Dev
summary: Use Semitexa Dev as the project-aware operating layer for orientation, planning, structural inspection, runtime debugging, durable work memory, and precise verification.
order: 50
locale: en
status: canonical
verified_against: 2026.09.19.1020
aliases:
  - AI tooling
  - agent tooling
  - developer tools
  - observatory
keywords:
  - semitexa-dev
  - ai:orient
  - ai:task
  - ai:ask
  - ai:context
  - ai:plan
  - ai:work
  - ai:epic
  - ai:trace
  - ai:verify
  - ai:observe
  - ai:report
  - project graph
  - structured JSON
related_documents:
  - get-started/ai-console
  - project-graph/overview
  - project-graph/inspection
  - project-graph/impact
  - cli/scaffolding-generators
  - cli/runtime-maintenance
---
# Semitexa Dev

Semitexa Dev is the project-aware operating layer for people and coding agents working on a Semitexa application.

It answers six practical questions:

1. What state is this project in?
2. What kind of task is this?
3. Where does the relevant behavior live?
4. What is running right now?
5. What should be verified after the change?
6. What must survive when the current context ends?

The agent remains the reasoning system. Semitexa supplies verified project facts, execution tools, durable work state, and targeted checks. Keeping those roles separate matters: a model can propose a change, while the project can prove what routes exist, what a class affects, which process failed, and whether the edited result is valid.

```semitexa-diagram
title: Semitexa Dev operating loop
node: orient | Orient | Read current project state | 0 | 0
node: classify | Classify | Choose recipe and risk | 1 | 0
node: inspect | Inspect | Ask structure and runtime | 2 | 0
node: plan | Plan | Score the exact file set | 3 | 0
node: edit | Edit | Apply the focused change | 3 | 1
node: verify | Verify | Run relevant checks | 2 | 1
node: record | Record | Preserve decisions and next step | 1 | 1
edge: orient -> classify | intent
edge: classify -> inspect | scope
edge: inspect -> plan | evidence
edge: plan -> edit | act
edge: edit -> verify | prove
edge: verify -> record | preserve
edge: record -> orient | next session
```

## The operating loop

For a normal code change, the Semitexa Dev loop is:

```text
orient → classify → inspect → plan → edit → verify → record
```

| Stage | Primary command | Result |
|---|---|---|
| Orient | `ai:orient` | Git state, active work, recent traces, last verification, and the next useful action |
| Classify | `ai:task` | A recipe, confidence score, risk hint, and suggested generator chain |
| Inspect | `ai:ask`, `ai:context`, `ai:review-graph:*` | Runtime and structural facts scoped to the task |
| Plan | `ai:plan` | Risk assessment for the recipe and exact file set |
| Edit | `make:*` or a focused manual edit | A change that follows discovered project conventions |
| Verify | `ai:verify` | The smallest relevant syntax, lint, structure, static-analysis, and test set |
| Record | `ai:trace`, `ai:work`, `ai:epic` | Durable decisions and next steps for a later session |

This is a decision loop rather than a mandatory ceremony. A focused one-file fix can stay inline. Work that spans modules, contains branching decisions, or may outlive the current context belongs in an epic with small work items.

## Start a session with facts

Use `ai:orient` before assembling project state from unrelated Git, task, and log commands:

```semitexa-command
bin/semitexa ai:orient --json
---
JSON envelope with artifact `semitexa-dev.ai-orient/v1`, current Git and work state, and contextual `next_command` suggestions.
```

The response combines repository state with active Semitexa work artifacts. Its `next_command` entries explain useful follow-ups based on the current state.

For a new executable request, classify the intent:

```bash
bin/semitexa ai:task "add tenant-aware invoice export" --json
```

The classifier returns a recipe and a confidence level. High confidence gives a reliable starting path. Low confidence is a signal to inspect the suggested alternatives or clarify the goal before running a generator.

## Choose the narrowest inspection tool

Use `ai:ask` for project facts that Semitexa can answer directly:

```bash
bin/semitexa ai:ask project --json
bin/semitexa ai:ask module --name=Billing --json
bin/semitexa ai:ask route --path=/invoices --method=GET --json
bin/semitexa ai:ask event --name=InvoiceIssued --json
bin/semitexa ai:ask mechanisms --area=ssr --json
bin/semitexa ai:ask logs --grep=invoice --level=ERROR --lines=200 --json
```

These commands describe discovered routes, handlers, resources, templates, services, events, logs, and framework mechanisms. They are preferable to broad text searches when the question is about framework structure.

Use `ai:context` after `ai:task` chooses a recipe:

```bash
bin/semitexa ai:context add_html_page --module=Billing --json
```

It returns nearby prior art and repository conventions, so a new page follows the module that already works instead of inventing a parallel pattern.

Use Project Graph when the question is about relationships or blast radius:

```bash
bin/semitexa ai:review-graph:query \
  --usages='Semitexa\Billing\Application\Service\InvoiceExporter' \
  --json

bin/semitexa ai:review-graph:impact \
  'Semitexa\Billing\Application\Service\InvoiceExporter' \
  --json
```

Project Graph follows structural edges such as `uses`, `implements`, `handles`, and `serves_route`. Full-text search remains useful for literal strings; it should not substitute for a dependency query.

## Plan against the real file set

Before a multi-file or uncertain edit, give `ai:plan` the recipe and the files you expect to touch:

```bash
bin/semitexa ai:plan add_html_page \
  --module=Billing \
  --files=packages/semitexa-billing/src/Application/Payload/Request/InvoicePayload.php,packages/semitexa-billing/src/Application/Handler/PayloadHandler/InvoiceHandler.php \
  --json
```

The result explains why the change is low, medium, or high risk. If the reported risk is higher than expected, reduce the file set, split the work, or inspect the newly exposed dependency before editing.

## Debug the runtime through the Observatory

`ai:observe` is the first stop for runtime behavior. It reports what actually ran instead of asking you to infer behavior from source alone.

```bash
bin/semitexa ai:observe ps
bin/semitexa ai:observe tail --kind=http --follow --duration=15
bin/semitexa ai:observe show --id=p-123 --source
```

The three views serve different questions:

| View | Use it for |
|---|---|
| `ps` | Live processes, recent completions, workers, and stale activity |
| `tail` | A bounded stream of new HTTP, queue, scheduler, or SSE lifecycles |
| `show` | One process with spans, queries, payload snapshot, timing, and optional source |

A request made with `?__trace=1` records a full waterfall. The same trace is available to humans at `/__trace` and to agents through `ai:observe show`.

When a recorded request needs controlled reproduction, replay it in the development sandbox:

```bash
bin/semitexa ai:observe replay --id=p-123 --mutate status='"cancelled"'
```

Replay rolls writes back and records the differences from the original process. This makes it useful for testing a hypothesis without leaving mutated application state behind.

For quick handler feedback without HTTP, auth, or middleware, use `ai:invoke`:

```bash
bin/semitexa ai:invoke \
  --route=/invoices \
  --payload='{"page":1}' \
  --json
```

The response states which pipeline layers were omitted. Treat it as a handler probe, then use an HTTP or browser check when the complete request pipeline matters.

## Keep long work recoverable

Semitexa stores long-lived work in three layers:

| Artifact | Responsibility |
|---|---|
| `ai:epic` | The outcome shared by several work items |
| `ai:work` | One executable leaf task with recipe, risk, context, and next step |
| `ai:trace` | Decisions, observations, failed hypotheses, and verification events |

Create an epic when work spans several modules or will not fit safely in one sitting:

```bash
bin/semitexa ai:epic start \
  --id=ep-invoice-export \
  --title="Ship invoice export" \
  --goal="Let tenant administrators export auditable invoice data." \
  --json

bin/semitexa ai:work start \
  --id=tk-invoice-export-http \
  --epic=ep-invoice-export \
  --title="Add the export endpoint" \
  --recipe=add_html_page \
  --risk=medium \
  --context-ref=packages/semitexa-billing \
  --next-step="Inspect the invoice route and generate the smallest export page." \
  --json
```

Write notes for the next working session: record the decision that would otherwise be debated again, the hypothesis already disproved, and the exact next command that restarts the work.

## Verify the change, not the whole universe

Run `ai:verify` after a coherent edit:

```bash
bin/semitexa ai:verify \
  --files=packages/semitexa-billing/src/Application/Handler/PayloadHandler/InvoiceHandler.php,packages/semitexa-billing/src/Application/Resource/Response/InvoiceResource.php \
  --json
```

Semitexa classifies the changed files and selects the relevant checks. Depending on the diff, that can include PHP syntax, handler and DI lint, template validation, module structure, static-analysis rules, generated reference drift, or focused tests.

Verification runs in a fresh CLI process and sees saved files immediately. Restart the server only before exercising the long-running HTTP worker through a browser, `curl`, or E2E test.

## Report framework defects with evidence

When a project must work around a Semitexa defect, capture the defect as part of the fix:

```bash
bin/semitexa ai:report \
  --title="Route inspection omits response template" \
  --summary="The route is valid, but ai:ask route does not report its discovered Twig template." \
  --evidence="bin/semitexa ai:ask route --path=/invoices --json → template is absent" \
  --workaround="Inspected the resource attribute directly." \
  --package=semitexa-dev \
  --json
```

`ai:report` searches for duplicates and can add another sighting instead of opening a parallel issue. Review the rendered report before publication, and keep consumer configuration, credentials, and private application code out of public evidence.

## Machine-readable by design

The `ai:*` commands expose stable JSON envelopes for automation. A typical envelope contains:

- an artifact and schema version;
- the requested result;
- confidence, omissions, or risk where relevant;
- `next_command` suggestions with reasons;
- verification or trace outcomes when the command produces them.

This contract lets a human read the same facts an agent consumes. It also gives integrations a stable surface without scraping colored terminal output.

## The local assistant is a separate entrypoint

`bin/semitexa ai` starts the optional local assistant backed by registered `#[AsAiSkill]` commands:

```bash
bin/semitexa ai --dry-run
bin/semitexa ai --yes
bin/semitexa ai:skills --json
```

The assistant translates natural-language intent into declared skills. The deterministic `ai:*` commands remain available whether or not a local language model is configured.

## Command reference

Use this page to choose the right workflow. Use the generated **AI commands reference** for every argument and option supported by the installed release:

```bash
bin/semitexa help ai:orient
bin/semitexa help ai:observe
bin/semitexa help ai:verify
bin/semitexa ai:ask capabilities --json
```

The capability manifest is the authoritative inventory for the running project because it reflects the packages and commands actually installed there.
