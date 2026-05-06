# ADR: Module Structure Cleanup and Package-Scoped Local Extensions

Status: accepted (2026-05-01)
Supersedes: prior ad-hoc package layouts and one-off `Application/<Layer>` shapes
Authoritative spec: [`packages/semitexa-docs/docs/MODULE_STRUCTURE.md`](../MODULE_STRUCTURE.md)

## Final outcome

Repo-wide PASS:

- `bin/semitexa ai:verify --all`: **36 / 36 packages, 0 violations** across the
  module-structure check (codes `unknown_directory`, `invalid_root_file`,
  `invalid_location`).
- `bin/semitexa test:run`: **2320 / 2320 tests** green.

This was reached by aligning each package on the canonical Semitexa layout
rather than by widening the global rule set. Two packages required
package-scoped local extensions (see below); no other escape hatches were
introduced.

## Core policy — canonical first

Every package in `packages/<pkg>/src/` follows the canonical layout:

- `Application/Service/<Feature>/`
- `Application/Db/<Adapter>/{Model,Repository}/`
- `Application/Console/Command/`
- `Application/Static/{css,js,img,fonts,svg}/`
- `Application/View/templates/...` (when SSR-rendering)
- `Domain/{Contract,Model,Enum,Event,Command}/`
- `Attribute/`
- `Exception/`

App modules in `src/modules/<Module>/` follow the same shape — they are
**not** Composer packages; they are application modules consumed by the
canonical app-module rules at the consumer site.

The validator is the source of truth for what is allowed where. If the
canonical layout makes a placement awkward, the first response is to fix
the placement, **not** the rule.

## Local extension policy — narrow and justified

A package-scoped `LocalModuleStructureExtension` (declared at
`packages/<pkg>/config/module-structure.php`) is permitted **only** when:

1. The package has a contract enforced by something **outside** the
   validator (Composer `type`, PSR-4 autoload target, distribution model)
   that the canonical layout cannot satisfy on its own.
2. The deviation is described as a payload boundary (an opaque sub-tree
   or a tightly scoped leaf), not as a relaxation of the global rules.
3. Every authorised path carries `opaqueOwner`, `opaqueReason`,
   `opaqueTodo`, and `rationale` so future readers can re-evaluate the
   exception in context.

Local extensions never widen rules for other packages. They are
strictly additive within the declaring package's `src/` tree.

## Current local extensions (the entire allow-list)

### `packages/semitexa-orm/config/module-structure.php`

Authorises five top-level directories — `Adapter/`, `Trait/`,
`Repository/`, `Query/`, `Metadata/` — and one top-level file
(`OrmManager.php`) at `packages/semitexa-orm/src/`. Each path uses
`MODE_LEAF_FILES_ONLY` with explicit `allowedFilePatterns`.

**Why**: the ORM ships shared persistence primitives (drivers,
repository templates, query builders, metadata tooling) that historically
predate the canonical `Application/Db/<Adapter>/{Model,Repository}/`
split and are consumed by name from outside the `Application/`
envelope. Hoisting them to `Application/Service/<Feature>/` would
break public consumers without changing the actual structural risk.
The local extension narrows the deviation to the smallest viable
surface (named directories, explicit file patterns) while keeping the
canonical layout in force everywhere else.

### `packages/semitexa-ultimate/config/module-structure.php`

Authorises two top-level directories — `modules/` and `registry/` — at
`packages/semitexa-ultimate/src/`, both `MODE_OPAQUE_INTERNAL` with
`opaqueOwner: 'ultimate'`.

**Why**: `semitexa/ultimate` is `composer.json` `type: "project"` — it is
the project skeleton consumers materialise via
`composer create-project semitexa/ultimate`. Its `src/` layout *is* the
consumer-project layout, and its PSR-4 autoload contract maps `App\\`
to `src/` and `App\\Registry\\` to `src/registry/`. Inside the
package, `src/modules/` ships scaffold-payload application modules
(e.g. `Hello/`); inside the consumer project, those same paths become
real app-module sites where the global app-module rules apply
unchanged. The opaque marker reflects exactly that: the validator
should not look inside scaffold payload here, because the canonical
rules will see the contents at the consumer site instead.

These are the only two local extensions in the repo. There is no
`packages/semitexa-dev/config/module-structure.php` — the file at that
path is the **global** spec, not a local extension.

## Important decisions captured during the cleanup

- **App modules are not Composer packages.** App modules live at
  `src/modules/<Module>/` and are validated by the global app-module
  rules. Packages under `packages/<pkg>/` are validated by the package
  rules. The two rule sets share many conventions but are independent;
  do not import package allowances into app modules or vice versa.
- **Canonical SSR aliases are bare module names.** `@SsrPolygon`,
  `@ThemeDemo`, `@<ModuleName>` are the forward-looking form. The
  legacy `@project-layouts-<Module>` aliases remain registered for
  back-compat (theme base templates, `DefaultErrorPageResource`,
  `ThemeAwareTwigLoader`'s parser) but new code uses the bare alias.
- **Theme static skins live under `Application/Static/css/skins/`.**
  Skin tokens are emitted under the canonical static surface; the
  per-skin contract is documented separately (skin-token canonical
  schema). No `theme/`-named top-level directory is required at the
  package level.
- **`Component`, `Page`, `Layout` are services.** These are not
  top-level structural primitives. Their classes live under
  `Application/Service/Component/`, `Application/Service/Page/`,
  `Application/Service/Layout/`. The validator rejects bare
  `Component/` etc. at package roots.
- **Console commands canonicalise to `Application/Console/Command/`**
  in both packages and app modules. Bare `src/Console/Command/`
  (packages) and top-level `Console/` (modules) are rejected by the
  structure validator (`P005` / `R001`).
- **DB-adapter `Model/` and `Mapper/` are separate canonical
  sublayers.** `*Mapper.php` belongs under
  `Application/Db/<Adapter>/Mapper/`, not `Application/Db/<Adapter>/Model/`.
  `Model/` holds resource models (`*Resource.php`,
  `*ResourceModel.php`) only; `Mapper/` is a peer sub-tree that holds
  the row-to-entity translators. Co-locating mappers next to resource
  models is rejected by the validator with `module_structure.invalid_location`.

## Guardrails

The following changes require an explicit operator decision and an
update to this ADR. They are **not** routine cleanup:

1. Adding a third local extension. The bar is the policy above:
   external contract, narrow scope, full justification metadata.
2. Widening the global rule set. The default response to a placement
   that fails validation is to move the file, not to widen the rule.
3. Adding compatibility bridges (`class_alias`, `@deprecated`
   re-exports, parallel namespaces) to "smooth over" a layout change.
   None were introduced during this cleanup; the policy is to update
   call sites instead.
4. Re-introducing legacy SSR alias prefixes in new code. New templates
   resolve via the bare module alias; legacy `@project-layouts-*` is
   parser-level back-compat only.

## Verification commands

```bash
bin/semitexa ai:verify --all       # expects 36/36 packages, 0 violations
bin/semitexa test:run              # expects 2320/2320 green
```

Both must pass before any module-structure-touching change merges.
