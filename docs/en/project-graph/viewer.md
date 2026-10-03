---
id: project-graph/viewer
section: project-graph
slug: viewer
title: Browsing the Graph
summary: The Graph view inside the Observatory, and the same view exported as one HTML file — a lazy tree per entry point, a layered DAG where fan-in is visible, findings, coverage gaps and links to recorded traces.
order: 25
locale: en
status: canonical
verified_against: 2026.09.30.1034
keywords:
  - graph view
  - observatory
  - ai:review-graph:show
  - --format=html
related_documents:
  - project-graph/inspection
  - project-graph/findings
  - project-graph/coverage
---
# Browsing the Graph

The project graph can be read without a query: the Observatory has a Graph view, and `ai:review-graph:show` writes the same view to a single file.

## In the Observatory

Open `/__observatory` and switch the header from **live** to **graph** (or press `g`). The view is available in dev mode only: it maps the application's internals, so monitor mode gets no switch, no viewer assets and no data.

| Part | What it shows |
|---|---|
| Entry points | Routes, commands and handlers. A route expands to its payload, the payload to its handler, the handler to what it depends on. Children load on expand. A class reached from more than one place carries a `×N` badge; a loop stops with `↺`; `⚠` marks a file with coverage gaps. |
| Focus | The selected node's dependencies, 1–4 steps deep, each class drawn once. Dependents are on the left, dependencies on the right, and a node's height grows with its fan-in across the whole graph. Amber edges close a loop; dashed edges and ghost nodes are references resolved only at runtime. Click inspects a node, double-click refocuses. |
| Node | Every edge in both directions, the coverage gaps in the node's file, the source link, and the recent recorded traces that ran the class. |
| Findings | The same unused classes and loops `ai:review-graph:findings` prints, each focusing its node, with the coverage line that says how far "no finding" can be trusted. |

Search (`/`) jumps to a class and opens the tree along the shortest path from an entry point. The address keeps the selection (`/__observatory#graph=<node id>`), so a link opens the same node.

The trace viewer links back: a class page under `/__trace/node` has **open in graph**. Going the other way, "recent traces" lists requests recorded with `?__trace=1`; an empty list means no persisted trace names the class, not that it never ran.

When the code has changed since the graph was built, the view says **stale**. Rebuild with:

```bash
bin/semitexa ai:review-graph:generate
```

## As a file

```bash
bin/semitexa ai:review-graph:show --format=html                      # var/evidence/inbox/graph-whole.html
bin/semitexa ai:review-graph:show --format=html --module=Orders      # var/evidence/inbox/graph-Orders.html
bin/semitexa ai:review-graph:show --format=html --output=var/tmp/focus.html --depth=2 'App\Orders\PlaceOrderHandler'
```

The file carries the viewer and the data inline and fetches nothing, so it opens from `file://` and a reviewer needs no running stack. The command prints the size: a focus is a few hundred kilobytes, the whole graph of a large project several megabytes. Recent traces are not part of a file.

The file is a map of your codebase — every class, route and wiring edge — so it is written under `var/`, which the web server does not serve. By default it lands in the evidence inbox: the next `bin/semitexa ai:evidence` command records it as private evidence of real data, moves it to `var/evidence/<id>/` and removes it after 14 days. `var/evidence/` is in the project's `.gitignore` (add it to an older project's). An `--output` anywhere else (`public/`, a tracked directory, a path outside the project) is refused unless you add `--allow-anywhere`. Sharing it is your decision: a pull request on a public repository, an issue or a chat upload publishes the map, so filter to the slice the reviewer needs and send it only where the code itself could go — `bin/semitexa ai:evidence publish <id> --to=… --allow-real-data`, run in your own terminal, records that you did.
