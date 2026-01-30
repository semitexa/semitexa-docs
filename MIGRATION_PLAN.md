# Documentation migration plan (Variant B — hybrid)

**Goal:** Move framework documentation from `crm-erp/docs/` into the framework repo under `packages/syntexa/`, so that:
- Shared docs live in `packages/syntexa/docs/`
- Package-specific docs live in `packages/syntexa/<package>/docs/`
- AI and developers discover capabilities per package and know what to install

**Source:** `crm-erp/docs/en/` (and `crm-erp/docs/README.md` if needed)  
**Repo root:** `syntexa/` (monorepo). Apps (e.g. crm-erp) reference framework docs via relative path to `packages/syntexa/`.

---

## Target structure (after migration)

```
packages/syntexa/
├── docs/                           # Shared framework docs (new)
│   ├── README.md                   # Index + package map for AI
│   ├── AI_REFERENCE.md             # AI quick reference (moved)
│   ├── architecture/
│   │   ├── ARCHITECTURE.md
│   │   └── OVERLAY_ARCHITECTURE.md
│   ├── guides/
│   │   ├── CONVENTIONS.md
│   │   └── EXAMPLES.md
│   └── MIGRATION_PLAN.md           # This file (can remove after migration)
├── core/
│   └── docs/                       # Core-specific (new)
│       └── attributes/
│           ├── README.md
│           ├── AsRequest.md
│           ├── AsRequestHandler.md
│           ├── AsResponse.md
│           ├── AsEntity.md
│           ├── AsDomainPart.md
│           └── Column.md
├── orm/
│   └── docs/                       # ORM-specific (new)
│       ├── README.md
│       ├── QUICK_START.md
│       ├── MIGRATIONS.md
│       ├── MIGRATION_GUIDE.md
│       ├── POSTGRESQL_SETUP.md
│       ├── RELATIONSHIPS_LOADING.md
│       └── EXAMPLES.md
├── dev-tools/
│   └── README.md                   # Already exists
├── window-manager/
│   ├── README.md                   # Already exists
│   └── EXAMPLE.md
└── ...                             # Other packages add docs/ when needed
```

**Path from crm-erp to framework docs:** `../../packages/syntexa/docs/`  
**Path from repo root (syntexa):** `packages/syntexa/docs/`

---

## Step-by-step plan

### Phase 1 — Create structure and shared docs

| Step | Action | Details |
|------|--------|--------|
| 1.1 | Create `packages/syntexa/docs/` | Root for shared framework documentation. |
| 1.2 | Create subdirs | `packages/syntexa/docs/architecture/`, `packages/syntexa/docs/guides/`. |
| 1.3 | Move architecture | Copy then delete (or move): `crm-erp/docs/en/architecture/*` → `packages/syntexa/docs/architecture/`. |
| 1.4 | Move shared guides | `crm-erp/docs/en/guides/*` → `packages/syntexa/docs/guides/`. |
| 1.5 | Move AI_REFERENCE.md | `crm-erp/docs/en/AI_REFERENCE.md` → `packages/syntexa/docs/AI_REFERENCE.md`. |
| 1.6 | Add `packages/syntexa/docs/README.md` | New index: purpose of this folder, link to architecture + guides, **package map** (list of packages with short description and link to each package’s `README` or `docs/`). So AI sees “what exists” and “where to install from”. |

### Phase 2 — Per-package docs

| Step | Action | Details |
|------|--------|--------|
| 2.1 | Create `packages/syntexa/core/docs/attributes/` | Attribute docs belong to core (attributes live in core). |
| 2.2 | Move attribute docs | `crm-erp/docs/en/attributes/*` → `packages/syntexa/core/docs/attributes/`. |
| 2.3 | Create `packages/syntexa/orm/docs/` | All ORM docs in one place. |
| 2.4 | Move ORM docs | `crm-erp/docs/en/orm/*` → `packages/syntexa/orm/docs/`. |

### Phase 3 — Index and “package map”

| Step | Action | Details |
|------|--------|--------|
| 3.1 | Write `packages/syntexa/docs/README.md` | Content: (1) This is the shared entry for Syntexa framework docs. (2) Links: architecture, guides, AI_REFERENCE. (3) **Package map** table: package name, one-line purpose, “docs” link (e.g. `../core/docs/`, `../orm/docs/`), “install” hint (e.g. `composer require syntexa/core`). So AI can see capabilities and install steps. |
| 3.2 | Optional: `packages/syntexa/README.md` | If missing, add short README at `packages/syntexa/` pointing to `docs/README.md` and listing packages. |

### Phase 4 — Point AI and links to new locations

| Step | Action | Details |
|------|--------|--------|
| 4.1 | Update `crm-erp/AI_ENTRY.md` | Replace all `docs/en/...` and `docs/...` with paths to framework docs. Use path from crm-erp: `../../packages/syntexa/docs/` for shared docs; `../../packages/syntexa/core/docs/` and `../../packages/syntexa/orm/docs/` for package docs. Update “Documentation structure” diagram and “Getting started”, “Important files”, “Quick reference” so every link targets the new locations. |
| 4.2 | Fix internal links in moved files | In moved MD files, fix relative links that pointed to `docs/en/...` or between sections so they work under `packages/syntexa/docs/` and `packages/syntexa/<package>/docs/`. |
| 4.3 | Code/attributes referencing docs | If any PHP/attributes reference `doc:` or file paths to old `docs/en/attributes/`, update to new path (e.g. relative to package root or a known base). |

### Phase 5 — crm-erp docs after migration

| Step | Action | Details |
|------|--------|--------|
| 5.1 | Remove migrated content from crm-erp | Delete `crm-erp/docs/en/` content that was moved (or the whole `crm-erp/docs/en/` if nothing project-specific remains). |
| 5.2 | Keep or add project-only docs in crm-erp | Keep `crm-erp/docs/` only for **project-specific** docs (e.g. crm-erp setup, deployment, app-specific modules). Add a short `crm-erp/docs/README.md` that says: “Project-specific docs only; framework docs live in `../../packages/syntexa/docs/`”. |

### Phase 6 — Cleanup and checks

| Step | Action | Details |
|------|--------|--------|
| 6.1 | Search for old paths | Grep repo for `docs/en/`, `docs/uk/` (if any), and `crm-erp/docs` references; update or remove. |
| 6.2 | Remove or archive this plan | After migration, delete `packages/syntexa/docs/MIGRATION_PLAN.md` or move to `var/docs/` in an app as archive. |

---

## File mapping (quick reference)

| Current (crm-erp/docs/en/) | New location |
|----------------------------|--------------|
| `AI_REFERENCE.md` | `packages/syntexa/docs/AI_REFERENCE.md` |
| `README.md` | Replaced by new `packages/syntexa/docs/README.md` (index + package map) |
| `architecture/ARCHITECTURE.md` | `packages/syntexa/docs/architecture/ARCHITECTURE.md` |
| `architecture/OVERLAY_ARCHITECTURE.md` | `packages/syntexa/docs/architecture/OVERLAY_ARCHITECTURE.md` |
| `guides/CONVENTIONS.md` | `packages/syntexa/docs/guides/CONVENTIONS.md` |
| `guides/EXAMPLES.md` | `packages/syntexa/docs/guides/EXAMPLES.md` |
| `attributes/*` | `packages/syntexa/core/docs/attributes/*` |
| `orm/*` | `packages/syntexa/orm/docs/*` |

---

## Package map (for `packages/syntexa/docs/README.md`)

Suggested table for AI discoverability (to be filled in README):

| Package | Purpose | Docs | Install |
|---------|---------|------|--------|
| core | Request/response, attributes, discovery, container, CLI | [core/docs/](../core/docs/) | (always required) |
| orm | Entities, migrations, repositories, PostgreSQL | [orm/docs/](../orm/docs/) | `composer require syntexa/orm` |
| user-api | HTTP API for auth/login | (README in package) | `composer require syntexa/user-api` |
| user-domain | Domain entities, AuthService | (README in package) | `composer require syntexa/user-domain` |
| user-frontend | Login/dashboard UI, layouts | (README in package) | `composer require syntexa/user-frontend` |
| core-frontend | Layouts, Twig, slots | (README in package) | `composer require syntexa/core-frontend` |
| dev-tools | Metrics, logs, profiler, AI endpoint | [dev-tools/README.md](../dev-tools/README.md) | `composer require syntexa/dev-tools` |
| inspector | Profiler, request watchers | (README in package) | `composer require syntexa/inspector` |
| window-manager | Window API, message bus | [window-manager/README.md](../window-manager/README.md) | `composer require syntexa/window-manager` |

(Adjust “Install” and “Docs” per actual composer names and existing READMEs.)

---

## Order of execution (recommended)

1. Phase 1 (create dirs, move shared docs, add README skeleton).  
2. Phase 2 (core/attributes, orm/docs).  
3. Phase 3 (finalize README with package map).  
4. Phase 4 (AI_ENTRY + internal links + code references).  
5. Phase 5 (crm-erp cleanup).  
6. Phase 6 (grep + cleanup).

This keeps the framework docs tree consistent before updating all references and avoids broken links during the move.
