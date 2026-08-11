# Information architecture

Where a page goes, what shape it takes, and how the 422 items of public surface
are covered without writing 422 pages by hand.

Companion to [DOCUMENTATION_OWNERSHIP.md](DOCUMENTATION_OWNERSHIP.md), which
settles *which repository* a page lives in. This settles *where in the hub*.

## The five slots stay

The corpus already has an architecture — the journey in
[SITE_MAP.md](../SITE_MAP.md): Why → Get Started → Build → Reference → AI. It
maps almost exactly onto the four kinds of documentation the industry argues
about, so there is nothing to gain by renaming it and a whole corpus to lose.

| slot | kind | answers | reader is |
|---|---|---|---|
| **Why Semitexa** | explanation | why it works this way | deciding |
| **Get Started** | tutorial | take me from zero to running | learning |
| **Build** (`en/<topic>/`) | how-to | how do I do this task | working |
| **Reference** | reference | what exactly does this do | checking |
| **AI** | audience | how should an agent operate here | an agent |

**AI is not a fifth kind, it is a fifth audience** — the one that reads the whole
corpus at once and cannot ask a follow-up question. It gets its own slot because
its needs differ in form, not in subject.

The rule that decides a page's slot: **what is the reader holding?** Nothing yet
(Why), a terminal (Get Started), a task (Build), a symbol (Reference), a session
(AI). A page that would answer two of those is two pages.

## Reference is generated

Today `REFERENCE.md` is a list of paths into `vendor/semitexa/core/docs/` — a
location the ownership policy has retired. It becomes the index of a reference
section that is **generated from `docs:truth-index`**, one entry per item:
signature, parameters, targets, and a usage example harvested from the codebase
rather than invented.

This is not a preference. The inventory counted 422 public surface items and
found 255 of them named nowhere. Hand-writing that is not a plan; hand-writing
the forty-odd that need judgement and generating the rest is.

Generated pages carry a provenance header and are regenerated on release. Do not
hand-edit them — if a generated page reads badly, the fix belongs in the
generator or in the code's own docblock.

**What stays hand-written:** anything that requires a choice. When to reach for
an attribute rather than its neighbour, why a mechanism exists, what the failure
modes are. Reference says what a thing *is*; Build says when you would want it.

## Which contracts are reference at all

98 DI contracts exist. Not all of them are public API: many are seams that let
the container swap an implementation, and listing them as missing pages would
inflate the backlog with work nobody needs.

The framework already marks its own answer in the namespace:

- **Documented surface** — interfaces under `…\Domain\Contract\` or `…\Contract\`.
  **65 of 98.** These are contracts in the sense a consumer means: something you
  implement or replace.
- **Internal seams** — everything else, mostly `…\Application\Service\…`.
  **33 of 98.** Not documented by default. A seam graduates the first time
  somebody has a reason to implement it; that reason is the trigger, not a count.

Two exceptions to review by hand rather than by rule: `Semitexa\Core\Pipeline\*`
holds interfaces like `PreHydrationAuthGateInterface` that read like extension
points despite living outside a `Contract` namespace.

## Proposals are not reference

`workspace/technical-design/` currently holds design documents for work that was
never built —
<!-- docs-lint-ignore -->
`#[AsWmApp]`, `#[RequiresAuth]`, `WmAppRegistry` — in a directory
that reads and lints exactly like reference. Three of the four remaining
`docs:lint` findings come from there.

Sorting the directory turned up three kinds, not two, so `workspace/` now has
three homes and each means one thing:

- **`proposals/`** — designs for work that was not built.
- **`audits/`** — point-in-time reviews of code as it was on a date.
- **`technical-design/`** — designs that shipped, kept as the record of why the
  code works the way it does.

Each page opens with a status line naming which it is. Where a proposal names an
attribute or command that does not exist, the line carries
`<!-- docs-lint-ignore -->`: the claim is deliberate, and a linter should be told
so explicitly rather than left to infer it from a directory name.

## The page contract

Every hand-written page, in this order:

1. **One sentence** saying what the reader will be able to do. Not what the page
   covers — what they will be able to do.
2. **Prerequisites**, or a line saying there are none.
3. **A runnable example.** Commands must execute and code must compile as
   written. An example that only reads correctly is the failure mode this corpus
   already has: `ai:review-graph:query --module=Demo` was documented, reads
   perfectly, and fails with "No query specified".
4. **The verified-against note** — the release the page was last checked at.
   `docs:lint` catches renamed symbols; it cannot catch prose that has quietly
   stopped being true, and a date tells a reader how much to trust it.
5. **Next steps** — two or three links, chosen for the reader's next question
   rather than for completeness.

Pages that break the contract are not rejected; they are marked. A page with no
runnable example is a page that needs one, and saying so in the page is more
useful than a policy nobody reads.

## Where consolidated package docs land

By subject, not by package. A reader looking for how the ORM maps a relation
should not need to know which package implements it — that requirement is
precisely why the old ownership rule failed.

| coming from | lands in |
|---|---|
| `core/docs/` — routes, attributes, module structure | `en/routing/`, `en/di/`, generated reference |
| `orm/docs/` — the ORM guide | `en/data/` |
| `platform-ui/docs/` — primitives, grammar, skins | `en/platform/` |
| `project-graph/docs/` | `en/project-graph/` |
| anything that is a proposal | `workspace/proposals/` |

`en/` has 17 sections today and three of them hold a single page — `runtime`,
`testing`, `validation`. Thin sections are a symptom, not a problem to solve by
merging: `validation` is thin because the framework's validation story is
unclear enough that a page could not be written, which is its own open question.

## What this does not decide

- **Versioning.** Whether the site serves one version or one per release tag is
  a publishing decision, and belongs with the step that puts the corpus online.
- **Search.** Same.
- **Localisation.** `en/` implies siblings. Nothing here assumes there will be
  any; the structure works either way.
