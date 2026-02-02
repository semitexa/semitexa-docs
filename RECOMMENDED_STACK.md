# Recommended stack (HTML apps)

For applications that serve **HTML pages** (SSR, Twig templates, layouts), use:

- **syntexa/core** — routing, Request/Handler/Response, discovery (required).
- **syntexa/core-frontend** — Twig integration, LayoutRenderer, layouts. **Required for HTML.** Do not implement your own Twig renderer in the project.

Templates and layout rendering are done **only** through core-frontend. Use Response DTO with `#[AsResponse(template: '...')]` and Twig templates in modules; the framework uses `Syntexa\Frontend\Layout\LayoutRenderer` from core-frontend to render them.

See [core/docs/ADDING_ROUTES.md](../core/docs/ADDING_ROUTES.md) (Responses: JSON and HTML pages) and [AI_QUICK_START.md](AI_QUICK_START.md).
