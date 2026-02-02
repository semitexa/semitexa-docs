# Syntexa — Quick start for AI

> **Purpose:** One short doc AI can read first (e.g. in `vendor/syntexa/docs/`) to get key rules even if the project's AI_ENTRY.md is outdated.

1. **New routes = only via modules**  
   Put Request/Handler in **modules** (`src/modules/`, `packages/`, or `vendor/`). Do **not** add routes in project `src/Request/` or `src/Handler/` (namespace `App\`) — they are not discovered. See [core/docs/ADDING_ROUTES.md](../core/docs/ADDING_ROUTES.md).

2. **HTML pages = Response DTO + Twig**  
   Do **not** render HTML manually in the Handler. Use a Response class with `#[AsResponse(template: 'path/file.html.twig')]` and Twig templates in the module (e.g. `Application/View/templates/`). Package **core-frontend** provides Twig and layouts. See [AI_REFERENCE.md](AI_REFERENCE.md) and [guides/CONVENTIONS.md](guides/CONVENTIONS.md).

3. **Working directory = var/docs**  
   Use the project's **`var/docs/`** for temporary or intermediate files (plans, notes, drafts). Content is not committed (`.gitignore`). Keeps `docs/` and project root clean.

4. **Do not patch vendor**  
   Do not modify framework code in `vendor/`. Use modules and conventions; if something is missing, extend via modules or open an issue.

5. **Where to read more**  
   - New pages/routes: [core/docs/ADDING_ROUTES.md](../core/docs/ADDING_ROUTES.md)  
   - Full AI reference: [AI_REFERENCE.md](AI_REFERENCE.md)  
   - Conventions & examples: [guides/CONVENTIONS.md](guides/CONVENTIONS.md), [guides/EXAMPLES.md](guides/EXAMPLES.md)

**After upgrading syntexa/core:** run `bin/syntexa init --only-docs` in the project to refresh AI_ENTRY.md and project docs from the framework template.
