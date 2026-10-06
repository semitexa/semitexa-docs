---
id: project-graph/coverage
section: project-graph
slug: coverage
title: What the Graph Could Not See
summary: Every impact and usage answer carries a coverage block, so "nothing uses this" counts as proof only when nothing that could hide an edge went unread.
order: 35
locale: en
status: canonical
verified_against: 2026.09.30.1034
keywords:
  - coverage
  - absence_is_proof
  - coverage gap
  - GraphIgnore
  - .graphignore
related_documents:
  - project-graph/impact
  - project-graph/inspection
  - project-graph/architecture
---
# What the Graph Could Not See

A missing edge and an edge the graph failed to see look the same in the stored graph. An answer built on absence — "no downstream impact", "no usages" — is only as good as what was read. So the graph records what it could not see, and every answer that could be wrong because of it says so.

## The coverage block

`ai:review-graph:impact --json`, the `--ndjson` summary line, `ai:review-graph:query --usages/--dependencies` (text, `--compact --json`, and a trailing `kind: coverage` NDJSON line) and `ai:review-graph:generate --json` carry a `coverage` object:

```json
{
  "complete": false,
  "gaps": {"dynamic_reference": 362, "extraction_failed": 5, "duplicate_class": 13},
  "unresolved_references": 27,
  "exclusions": {"dir:vendor": 3, "swoole-src/": 1},
  "absence_is_proof": false,
  "relevant_total": 9,
  "relevant": [{"kind": "dynamic_reference", "file": "...", "line": 92, "subject": "...", "detail": "..."}]
}
```

- `complete` — the whole graph has no gaps at all.
- `absence_is_proof` — for the node you asked about, no gap could hide an edge to or from it. When it is `false`, "zero impact" means "none found", not "none exist"; the text output says so.
- `relevant` — the gaps that make it `false` (up to 20; `relevant_total` counts all).

Plain `query --json` keeps its bare list of edges; use `--ndjson` or `--compact --json` to get the block.

## Kinds of gap

| Kind | Meaning | Relevant to a node when |
|---|---|---|
| `parse_error` | The file does not parse; nothing it declares is in the graph. | always |
| `extraction_failed` | An extractor threw on the file (usually an attribute whose arguments name a class the process cannot load); that extractor's edges are missing, the rest are there. | always |
| `dynamic_reference` | Code refers to a class known only at runtime: `new $class`, `$class::boot()`, `$x instanceof $name`. | the file is in the node's module |
| `ignored` | The class is marked `#[GraphIgnore]`. | it is the node |
| `duplicate_class` | The file declares a class the graph already holds from another file. | it is the node |
| `unresolved_reference` | A class is referenced but no scanned file declares it, while its namespace has declared classes (a typo, a move, a deletion). Computed on demand, never stored. | it is in the node's namespace |

Gaps live and die with their file: re-indexing a file replaces its gaps, deleting it removes them.

## Keeping things out on purpose

`#[GraphIgnore(reason: '...')]` on a class keeps its nodes and outgoing edges out of the graph. References other classes make to it stay, and the `ignored` gap carries the reason, so a question about the class is answered with "not looked at" rather than "nothing there".

`.graphignore` at the project root lists directory patterns (`generated/`) and file-name patterns (`*.generated.php`). A directory pattern prunes the whole directory. What each rule kept out of the last scan is in `exclusions`; `vendor`, `var`, `node_modules` and `.git` are always skipped and counted, but are not gaps — they are not project code.
