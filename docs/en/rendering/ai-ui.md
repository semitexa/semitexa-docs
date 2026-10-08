---
id: rendering/ai-ui
section: rendering
slug: ai-ui
title: Screens an AI Composes
summary: How a model composes a screen as a UI tree, how the server checks it against the same catalog and permissions a person gets, how repair errors go back to the model, and how agents reach it over MCP.
order: 141
locale: en
status: canonical
verified_against: 2026.10.03.1952
keywords:
  - semitexa.ui-tree/v1
  - UiTreeValidator
  - UiTreeComposer
  - "#[AsUiContract(agent:)]"
  - "#[AsUiTreeAction]"
  - ui:tree:validate
  - ui:mcp
  - MCP
---

# Screens an AI Composes

A model may compose a screen, and the server checks it with the same catalog and the same
permissions a person composing it gets. The model never writes HTML. It writes a **UI tree**: the
components to use, their props, and where each goes. The server validates the tree and draws it
through the same renderers a template uses. A tree that fails is never drawn: the model is told
exactly what to fix.

## The UI tree

A tree is a flat JSON document. Its nodes refer to each other by id.

```json
{
    "version": "semitexa.ui-tree/v1",
    "root": "overview",
    "nodes": {
        "overview": {"type": "platform.card", "props": {"title": "Products"}, "children": ["count", "add"]},
        "count": {"type": "platform.stat", "props": {"label": "Products", "value": {"$data": "/count"}}},
        "add": {"type": "platform.button", "props": {"text": "Add a product", "href": {"$action": "add"}}}
    },
    "data": {"count": "3"},
    "actions": {"add": {"kind": "create", "screen": "playground.products"}}
}
```

- **`nodes`** — each node has a `type` (a component or primitive name), `props`, and optionally
  `children` (ids) and a `slot` (which of its parent's slots it goes into; the parent's first slot
  when left out).
- **`data`** — the data model. A prop bound with `{"$data": "/json/pointer"}` takes the value at
  that pointer.
- **`actions`** — named server actions. A prop set to `{"$action": "name"}` takes the action's
  value, such as the path a button opens. An agent picks an action kind from a closed list; it
  cannot name a handler.

The shape follows A2UI (flat, id-referenced, a separate data model, named actions). It is not the
A2UI wire format: an adapter for that comes when the spec is pinned.

## What the server checks

`UiTreeValidator` reports every fault at once, so a model repairs the whole answer in one turn.

- **Shape.** Version, root, ids and node fields, children that exist and are used once, no cycles,
  nothing the root does not reach, at most 500 nodes and 32 levels.
- **Components.** Each `type` is a component open to agents, and one this visitor may use.
- **Props.** Each prop is declared by the component's contract, has its type and allowed values,
  and none it requires is missing. A bound prop is checked by the value it binds to.
- **Slots.** Children go only into slots the component declares.
- **Actions.** Each `$action` names one of the tree's actions, and each action passes its kind's
  own check. For example, a CRUD create needs a screen that exists and the visitor's create
  permission on it.

Each fault says where and what was expected:

```json
{
    "code": "tree.prop_unknown",
    "path": "/nodes/count/props/valeu",
    "message": "platform.stat has no prop \"valeu\".",
    "expected": "label, value, delta, trend, caption",
    "got": "valeu",
    "hint": "Did you mean \"value\"?"
}
```

`path` is a JSON Pointer into the tree. The codes include `tree.component_unknown`,
`tree.component_forbidden`, `tree.prop_invalid`, `tree.prop_required`, `tree.slot_unknown`,
`tree.action_forbidden` and `tree.unreachable`.

## Opening a component to agents

A component is open to agents when its `#[AsUiContract]` says so:

```php
#[AsUiContract(
    summary: 'Revenue for a period.',
    props: [new UiProp('period', required: true, values: ['day', 'week', 'month'])],
    agent: true,                    // default: previewSafe
    permission: 'reports.view',     // who may be shown it
)]
```

- **`agent`** defaults to `previewSafe`.
- **`permission`** is checked through the same authorizer as routes. A visitor without it is never
  told the component exists: it is left out of the catalog, the prompt and the schema.

Behaviors are never open. A component without a contract cannot be checked, so it is never open
either.

## Actions

An action kind is a class with `#[AsUiTreeAction(kind: '…')]` implementing
`UiTreeActionKindInterface`. It has three methods:

| Method | What it does |
|---|---|
| `check()` | Returns the action's faults. |
| `propValue()` | Returns what the prop that uses it receives. |
| `describe()` | Tells the model how to write it, and lists only what this visitor may use. |

Built in:

| Kind | Shape | Value |
|---|---|---|
| `navigate` | `{"kind": "navigate", "to": "/path"}` | a same-site path |
| `create` | `{"kind": "create", "screen": "<crud screen id>"}` | the screen's create dialog |
| `edit` | `{"kind": "edit", "screen": "…", "record": "<id>"}` | one record's edit dialog |

The action is authorized when the tree is checked, and again when it runs: the screen it opens
checks its own permission.

## Composing

`UiTreeComposer::compose($description)` runs the loop:

1. It asks the model with the visitor's catalog. The prompt is `platform-ui.ui-tree.compose`.
2. It checks the answer.
3. If the answer fails, it sends the faults back as a correction turn
   (`platform-ui.ui-tree.repair`).
4. It stops after three rounds or at the first sound tree, and draws only a sound tree.

```php
$result = $composer->compose('A products overview with a way to add one.');
// ['tree' => ?UiTree, 'html' => ?string, 'rounds' => [...], 'failure' => ?string]
```

It works with any `LlmProviderInterface`: JSON is taken from the reply, inside code fences or not.
`ScriptedProvider` (semitexa/llm) answers with fixed replies, for tests and demos.

A component's `#[UiOn]` handler can call the composer directly, because component handlers
receive their `#[InjectAsReadonly]` services before they run.

## From the command line

```bash
bin/semitexa ui:tree:catalog --grant=catalog.read          # what this visitor may compose from
bin/semitexa ui:tree:catalog --grant=catalog.read --prompt # the prompt text a model is given
bin/semitexa ui:tree:catalog --schema                      # adds the JSON Schema of a whole tree
bin/semitexa ui:tree:validate screen.json --grant=catalog.read,catalog.create
bin/semitexa ui:tree:render screen.json --grant=catalog.read
bin/semitexa ui:tree:compose "A products overview" --grant=catalog.read
```

- **`--grant`** names the permissions of the visitor to check for. Repeat it, or separate the
  permissions with commas.
- **`ui:tree:validate`** prints one JSON envelope (`semitexa.platform-ui.ui-tree-check/v1`). It
  exits 1 when the tree is refused.

## Over MCP

`bin/semitexa ui:mcp --grant=…` is an MCP server on stdio, for a coding agent or an MCP host.

```json
{"mcpServers": {"semitexa-ui": {"command": "bin/semitexa", "args": ["ui:mcp", "--grant=catalog.read"]}}}
```

| Tool | Answers with |
|---|---|
| `ui_catalog` | the components and action kinds for the visitor |
| `ui_schema` | the tree's JSON Schema |
| `ui_validate` | the faults, as above |
| `ui_render` | an embedded `ui://semitexa/tree/<hash>` resource with the HTML, also readable through `resources/read` |

There is no HTTP door for it. In the browser, a page reaches the composer through its components,
over KISS and HUG like everything else.

## Proof in the Playground

| What | Where | Checked by |
|---|---|---|
| admin: first answer refused with three faults, second drawn | `/playground/ai/compose` | `compose-screen.spec.ts` |
| viewer: "Add a product" refused every round, nothing drawn | `/playground/ai/compose` (as `viewer`) | `compose-screen.spec.ts` |
