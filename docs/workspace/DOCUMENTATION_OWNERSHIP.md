# Documentation Ownership Policy

This document defines where Semitexa documentation belongs, which location is canonical for each type of knowledge, and how to avoid documentation drift.

## Purpose

Keep documentation close to the code that owns it, while giving humans and AI agents a single, unambiguous entrypoint to everything that is not package-local.

The problem this policy addresses is not that documentation exists in several places — some distribution is unavoidable in a monorepo. The problem is **unclear ownership and duplicated truth**.

## Core Rule

Each documentation topic has exactly one canonical owner. Every other copy must be one of:

- a short summary that links to the canonical source,
- a scaffold template that is copied into generated projects,
- a generated artifact produced from a canonical source.

No other copy is allowed to silently become a second source of truth.

## Canonical Layers

### 1. Framework and workspace documentation

Canonical location:

- `packages/semitexa-docs/`

`packages/semitexa-docs` is the **single official documentation source for the Semitexa framework**. It is the only location the repository treats as canonical for:

- Semitexa philosophy, positioning, and public product docs
- Getting started guides and onboarding material
- Repository-wide architecture and design decisions
- Cross-package policy, workflow, and toolchain reference
- AI navigation for the framework
- Documentation ownership policy (this document)

Inside `packages/semitexa-docs/`, two surfaces are used:

- `packages/semitexa-docs/docs/` — **public/product-facing docs**: philosophy, getting started, guides, reference map, localized content under `en/`.
- `packages/semitexa-docs/docs/workspace/` — **monorepo and workspace docs**: architecture, cross-package policy, toolchain workflow, technical-debt tracking, audit reports.

### 2. Root `./docs/` — not an official location

The repository does **not** treat a root-level `./docs/` directory as canonical. The framework does not generate, scaffold, or write into it. Installer and `init` flows do not create it. Tooling does not depend on it.

If an individual developer creates a `./docs/` folder in their own project for private notes, that is their choice. The framework makes no claim on that path and does not guarantee it will remain untouched by future local conventions — but it will never seed, read, or assume that directory as part of Semitexa itself.

### 3. Package-specific documentation

Canonical location:

- `packages/semitexa-docs/` — the same place as everything else.

A package does **not** own prose about its own behaviour. Package concepts, runtime behaviour, attribute and API reference, examples and migration notes all live in the hub, filed by subject rather than by which package happens to implement them.

This reverses the earlier rule, which made `packages/<package>/docs/` canonical for package behaviour. That rule optimised for keeping words next to the code that owns them, and it cost more than it returned:

- A reader had to know which package implements a thing before they could find out what it does — the exact knowledge a newcomer does not have.
- The framework's own machinery already assumes one root: `FileDocumentRepository` hardcodes the docs package, which is why a site that kept its content locally had to ship a duplicate reader to serve it.
- Ownership by proximity did not prevent drift. It distributed it: 28 of 33 packages shipped no docs at all, and the pages that did exist went stale unobserved.

If a code change belongs to one package, the documentation for that behaviour is still updated in the same change — in the hub.

#### What stays in a package

Three files, and none of them are prose about behaviour:

- `README.md` — the entrypoint (see section 4).
- `CHANGELOG.md` — release notes. These are per-tag, and `update:changelog` reads them from each package at release time; centralising them would break that.
- `docs/MODULE_STRUCTURE.md` — **not documentation.** It is the local extension of the module-structure spec, paired with the package's `config/module-structure.php` and loaded by `ModuleStructureSpecLoader::LOCAL_EXTENSION_DOC_REL_PATH`. Move it and `ai:verify` silently stops enforcing that package's structure rules. It is configuration that happens to be written in Markdown.

Anything else under `packages/<package>/docs/` is a leftover and belongs in the hub.

### 4. Package entrypoints

Canonical location:

- `packages/<package>/README.md`

Each package `README.md` is a short entrypoint only:

- what the package does
- when to use it
- where its documentation lives — a link into `packages/semitexa-docs/`, which is now the only possible destination

Package READMEs are not a second reference manual, and no longer a fork in the road.

### 5. Scaffold and generated-project documentation

Canonical location:

- `packages/semitexa-ultimate/` scaffold assets

Scaffold documentation exists only for files that are copied into generated user projects:

- generated `README.md`
- generated `AGENTS.md`
- generated `AI_ENTRY.md`
- generated `AI_NOTES.md`
- generated root-level `AI_CONTEXT.md`

Scaffold files live at generated-project **root**, not inside a generated `docs/` directory. The installer does not create a `docs/` directory in user projects.

Scaffold docs are templates for new-project authors, not framework reference.

### 6. Drafts, research, and working notes

Canonical location:

- `var/docs/`

`var/docs/` is the workspace's **scratch area**:

- technical designs
- research notes
- release notes
- experiment results
- drafts and temporary AI working files
- remediation reports

`var/docs/` is never canonical framework or package documentation.

## Duplication Rules

Duplication is allowed only when:

- a scaffold template intentionally copies content into a generated project,
- a navigation page links to a canonical source with a short summary,
- a generated artifact is produced from a canonical source.

Duplication is **not allowed** when two files both require manual editing to describe the same behavior. If two manually maintained documents explain the same thing, one becomes canonical and the other is reduced to a link or removed.

## Cross-Linking Rules

Cross-links are required when knowledge spans layers:

- public/product docs in `packages/semitexa-docs/docs/` link into `packages/semitexa-docs/docs/workspace/` and into package docs for technical detail
- workspace docs link into package docs for package-specific behavior
- package docs link up to `packages/semitexa-docs/docs/workspace/` for cross-cutting policy
- scaffold docs link to canonical docs but do not replace them
- AI entrypoints point only to real canonical locations

Broken or stale links to moved documentation are policy violations and are fixed immediately.

## AI Navigation Rules

Every documentation layer provides a clear entrypoint:

- **framework**: `packages/semitexa-docs/docs/README.md`
- **workspace/cross-cutting**: `packages/semitexa-docs/docs/workspace/README.md`
- **package**: `packages/<package>/README.md` — one hop, into the hub
- **generated project**: root `AI_ENTRY.md` + root `AI_CONTEXT.md`

AI-oriented files prefer canonical links over duplicated explanation.

If an AI entrypoint references a location that no longer exists or is no longer canonical, the entrypoint is updated before any new documentation is added elsewhere.

## Decision Rules

When creating or moving a document, use these rules in order:

1. If the document explains behavior owned by one package, place it in that package.
2. If the document is public/product-facing (philosophy, getting started, guides, site map), place it in `packages/semitexa-docs/docs/`.
3. If the document is repository process, cross-package policy, toolchain reference, or workspace architecture, place it in `packages/semitexa-docs/docs/workspace/`.
4. If the document is copied into generated projects, treat it as scaffold content and place it under `packages/semitexa-ultimate/`.
5. If the document is exploratory, temporary, or incomplete, place it in `var/docs/`.

## Maintenance Expectations

When documentation changes:

- update the canonical source first
- update or remove summaries that link to it
- fix entrypoints that point to stale paths
- avoid introducing new hand-maintained duplicates

When in doubt, move a document closer to the code that owns the behavior rather than centralizing everything.

## Enforcement Heuristic

Before merging a documentation change, ask:

1. Who owns this knowledge?
2. Is this the canonical location for that owner?
3. Does another manually maintained document already describe the same thing?
4. If this is not canonical, does it clearly link to the canonical source?
5. Does any tooling write to, scaffold, or assume a root-level `./docs/` directory? (If yes, that tooling is broken — this policy forbids root `./docs/` as a framework concern.)

If any answer is unclear or a violation is found, the documentation structure is still wrong and is corrected before more content is added.
