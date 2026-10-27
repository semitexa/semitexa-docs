---
id: rendering/components
section: rendering
slug: components
title: Components
summary: Reusable, attribute-registered UI components, discovered automatically from the classmap.
order: 40
locale: en
status: published
verified_against: 2026.09.19.1020
keywords:
  - "#[AsComponent]"
  - "#[UiPart]"
  - "#[UiOn]"
  - UiResponsePatch
  - UiInteractionResult::dispatching()
  - EventDispatcherInterface
  - UiIslandInterface
  - platform-ui.island.feed
  - "#[UiState]"
---
# Components

Attribute-registered SSR components with ONE interaction model: a part's event reaches its handler through HUG — the component's own `#[UiOn]` method, or a `#[HandlesUiEvent]` service bound to that (component, part, event) — and the handler answers with effects.

## How it works

Components are discovered at boot from the classmap through `#[AsComponent]`. Every rendered instance gets one id (`_component_id`, `uci_…`); its parts (`#[UiPart]`) are what the browser can act on. Platform UI's `ui_event_manifest()` signs a context per (part, event) — the component name, the instance, and the props it was rendered with — so the server never trusts the browser for any of them.

A click or change on a part posts the canonical envelope to `POST /__semitexa_hug`. The dispatcher verifies the signed context and calls the matching `#[UiOn]` method (or a `#[HandlesUiEvent]` service). The method returns a `UiInteractionResult`:

- **effects** for the page — `UiResponsePatch::rerender($instanceId, $props)` (the server re-renders the component with its signed props plus these, and the browser morphs it in place), `replace`, `append`, `prepend`, `remove`, `focus`, `reset` (a `<form>` back to its defaults), `open` / `close` (a modal, offcanvas or `<dialog>` — `close` aimed at the instance itself closes the overlay the component sits in), `setText`, `setValue`, `setAttribute`, `redirect`, `toast`, `dispatch` (a browser event), `url` (query parameters of the page's address — usually written for you by `#[UiUrl]`). The same list arrives inline on the HUG reply, or on the page's KISS stream when it has one.
- **domain events** for the application — `->dispatching(new SomethingHappened(...))` reaches ordinary `#[AsEventListener]`s once the interaction succeeded.

```php
#[AsComponent(name: 'counter', template: '@app/components/counter.html.twig')]
#[UiPart(name: 'increment', uses: ButtonPrimitive::class)]
final class CounterComponent
{
    #[UiOn(part: 'increment', event: 'click')]
    public function onIncrement(UiInteractionEvent $event): UiInteractionResult
    {
        $count = (int) ($event->props()['count'] ?? 0) + 1;

        return UiInteractionResult::patch([UiResponsePatch::rerender($event->instanceId, ['count' => $count])])
            ->dispatching(new CounterIncremented($count));
    }
}
```

### State in the URL — `#[UiUrl]`

A prop that should survive a reload or travel in a link is bound to the query string:

```php
#[AsComponent(name: 'blog.search', template: '@app/components/search.html.twig')]
#[UiUrl(prop: 'query', as: 'q', maxLength: 80)]
#[UiUrl(prop: 'scope', values: ['all', 'docs', 'code'], except: 'all', history: UiUrl::HISTORY_PUSH)]
final class SearchComponent { … }
```

- **On a page load** the value comes from `?q=` — attacker input, so it is validated (`type` string|int|bool, `values` allow-list, `maxLength`, `min`/`max`); an invalid value leaves the prop as the caller rendered it. Deferred components get it too.
- **After an interaction** re-renders the component with a different value, a `url` effect writes it back: `replace` (default) rewrites the current history entry, `push` adds one, and Back returns to the previous value. A value equal to `except` (or null) drops the key.
- A re-render after an interaction keeps the props its handler chose — the overlay applies to page loads (GET) only.

Grids keep their view in the URL with the `urlState` prop: `component('platform.grid', {endpoint: …, urlState: 'leads'})` writes `leads-q`, `leads-sort`, `leads-filter`, `leads-perPage` and `leads-page`. A link is restored only as far as the grid's contract allows (known sort and filter fields, offered page sizes); the rest is dropped.

Per-row actions are a `rowActions` column: `{label, href}` links or `{label, route, method?, confirm?}` buttons, with `{field}` placeholders filled from the row (URL-encoded, and only root-relative targets are used). A `confirm` text asks in a native modal `<dialog>` first; the button then posts to its route with the CSRF header and the grid refreshes.

```twig
{{ component('platform.grid', {endpoint: '/blog/articles/feed', rowActions: [
    {label: 'Edit', href: '/blog/articles/{id}/edit'},
    {label: 'Delete', route: '/blog/articles/{id}/delete', confirm: 'Delete “{title}”?'},
]}) }}
```

### Server actions: row, bulk, header

`serverActions` run on the server through HUG, not through a route per action. `actionHandler` names the `#[AsGridAction]` that runs them:

```twig
{{ component('platform.grid', {
    endpoint: '/blog/articles/feed',
    actionHandler: 'blog.articles',
    serverActions: [
        {id: 'publish', label: 'Publish', scopes: ['row', 'bulk']},
        {id: 'delete', label: 'Delete', scopes: ['row', 'bulk'], tone: 'danger',
         confirm: 'Delete “{title}”?', confirmBulk: 'Delete {count} articles?'},
        {id: 'rebuild', label: 'Rebuild index', scopes: ['header']},
    ],
}) }}
```

```php
#[AsService]
#[ExecutionScoped]
#[AsGridAction('blog.articles')]
final class ArticleGridActions implements UiGridActionInterface
{
    public function name(): string { return 'blog.articles'; }

    public function handle(UiGridActionContext $context): UiGridActionResult
    {
        // $context->action->id, $context->scope ('row' | 'bulk' | 'header'), $context->ids, $context->props
        return UiGridActionResult::done('Published 3 articles.', 3);
    }
}
```

- **Placement.** A `row` action is a button in each row. A `bulk` action adds a checkbox column, with select-all for the page, and a bar that counts the selection. A `header` action sits above the grid and acts on no rows.
- **Confirmation.** `confirm` asks first, in the grid's modal dialog. In it, `{field}` is the row's value and `{count}` is the size of the selection. `confirmBulk` is the question for a selection, when it reads differently.
- **While it runs**, the grid is `aria-busy` and its action buttons are disabled. The answer is a toast. On success the selection is cleared, and the rows refresh live from the write (a pull grid pulls once).
- **Trust.** The action list, `actionHandler` and every other prop are signed into the grid. The server runs only an action the grid was rendered with, in a scope it offers, on at most 200 plain ids. The handler must still check that the visitor may run it, and which of the ids they may touch.
- **Selection** covers only the rows on screen. A row that leaves the page leaves the selection, so a bulk action never reaches a row the visitor can no longer see.

[CRUD screens](crud-screens.md) build this from their `actions()`.

`script:` attaches a client mount (`SemitexaComponent.register(name, mount)`) for behaviour that never needs the server.

## The command palette

`{{ component('platform.command-palette', {placeholder: 'Search or jump to…'}) }}` adds a Ctrl+K / ⌘K palette. It is a native modal `<dialog>`, and any `<button commandfor="command-palette" command="show-modal">` opens it too; the component renders such a trigger unless `trigger: false`.

The listbox has three sections:

- **Recent:** the last commands chosen in this browser, shown while the query is empty.
- **The page:** elements marked `data-ui-command="Label"` (with optional `data-ui-command-group` and `data-ui-command-keywords`) and the page's navigation links, filtered in the browser. Choosing one clicks that element.
- **The server:** the search box is a part of the component, so typing reaches it through HUG (debounced, signed, session-bound). Every `#[AsCommandSource]` service then answers for the visitor.

```php
#[AsService]
#[AsCommandSource]
final class ArticleCommands implements UiCommandSourceInterface
{
    #[InjectAsReadonly]
    protected ArticleRepository $articles;

    public function search(string $query, int $limit): iterable
    {
        foreach ($this->articles->titled($query, $limit) as $article) {
            yield new UiPaletteItem($article->title, '/articles/' . $article->id, group: 'Articles', icon: 'file-text', permission: 'content.read');
        }
    }
}
```

- **Permissions:** a command with a `permission` is checked by the same `Authorizer` a `#[RequiresPermission]` route uses, for the visitor's subject. A command the visitor may not see never leaves the server, and a permission that cannot be checked counts as not granted.
- **Destinations:** `href` must be a same-origin path.
- **Limits:** each source returns at most 8 commands, and 20 in total.
- **Resolution:** sources are resolved for each request. Mark one `#[ExecutionScoped]` when it injects request state.
- **Keyboard:** ArrowUp / ArrowDown move through all sections, Enter opens, Esc closes.
- **Stale answers:** an answer to a query the visitor has already typed past stays hidden.
- **Duplicates:** one destination shows once, in its first section (recent, then page, then server). A shell page's sidebar link and a server command for the same page are one option.

## Sign in, sign up, settings

The auth pages are components on `platform.form`. The project names the `#[AsFormSubmitAction]` that does the work, so the pages carry no backend of their own.

```twig
{% extends '@platform-ui/layouts/auth.html.twig' %}      {# one centred card #}
{% block main %}
    {{ component('platform.sign-in', {submitAction: 'app.auth.sign-in', identifier: 'email', forgotHref: '/forgot', signUpHref: '/join'}) }}
{% endblock %}
```

| Component | Fields | Notes |
|---|---|---|
| `platform.sign-in` | identifier (`email` or `username`), password, "keep me signed in" (`remember`) | `autocomplete` set for password managers |
| `platform.sign-up` | name, email, password + repeat (`minPassword`, `sameAsField`), optional terms (`termsHref`) | |
| `platform.forgot-password` | email | answer the same whether or not the address exists |
| `platform.reset-password` | password + repeat | `token` rides the form's **signed** props: the action reads `$context->props['token']` |

A sign-in action writes the session (for example `AuthSessionWriter::setAuthenticated()`, which rotates the session id; pass the user's `credentialStamp`, see below) and answers `redirectingTo(...)`. A wrong password is best a field error that does not say which half failed. Count failed sign-ins per client address in the action too, with `RateLimiter::attempt()` (`#[InjectAsMutable]`, the limiter `platform.auth.forgot-password` uses too), and release the attempt when the sign-in succeeds: the account lockout counts guesses at one account, not one client guessing across many. The OS login allows 10 failures a minute and 50 an hour per address. On a site with tenants, check platform users with `UserAuthenticator::attemptAt($email, $password, $site)`, with the site from `TenantContextStoreInterface::tryGet()`: it checks only the accounts that may act on the request's site, before any password. If you check the password first and the site after, another site's account is checked and stamped as signed in, a password re-hash makes the right guess measurably slower, and guesses at one site lock out other sites' accounts.

### Password reset

`semitexa/platform-user` holds the backend: `PasswordResetService` issues a single-use link that expires, keeps only the SHA-256 of its token, and limits how many links one account is sent. The two actions ship with platform-ui:

```twig
{{ component('platform.forgot-password', {submitAction: 'platform.auth.forgot-password', signInHref: '/sign-in'}) }}

{# the page the link opens: it reads token from the query #}
{{ component('platform.reset-password', {submitAction: 'platform.auth.reset-password', token: token, signInHref: '/sign-in'}) }}
```

- `platform.auth.forgot-password` asks the service for a link for the address on the current site (the request's tenant; on a single-site install an address registered on two sites gets none), limits one client address to five requests in fifteen minutes, and answers the same whether or not the address has an account — in wording and in time: inside the server every answer goes at `PLATFORM_USER_RESET_ANSWER_MS` (1500 ms), whether the link has been sent by then or not; the sending carries on, and a failure after the answer is only logged (the `auth` channel). In the CLI, where nothing runs beside the request, every answer takes at least that long. `0` answers as soon as the request is done. Behind a reverse proxy, set the trusted proxies, or every visitor shares the proxy's address and its limit. Owners (accounts with no site) reset from the root site. Without a mailer configured it says reset is not available.
- `platform.auth.reset-password` checks the repeat, resets the password with the signed `token`, and after a reset sends the browser to `signInHref` when it is a path on this site (a full URL, `//host` or `/\host` is ignored). A used or expired link is refused with a prompt to ask for a new one. The token is in the page's form and its URL: serve that page with `Referrer-Policy: no-referrer`.

A project that wants other wording or a step of its own still writes its own actions on `PasswordResetService`, the same way.

The link goes out by mail (semitexa/mail) once `PLATFORM_USER_RESET_URL` says where it points: an absolute URL with `{token}` in it, such as `https://example.com/reset-password?token={token}`. The site's address is taken from there, never from the request's `Host` header, which the person asking controls. Until it is set, `request()` refuses every address alike. A mail that could not be sent is logged, without the token, and the form still answers as for any address. The mail is plain text, in English, valid for `PLATFORM_USER_RESET_TTL_SECONDS` (an hour by default); for other wording or language, or a multi-site install whose sites live on different hosts, bind your own `PasswordResetMailerInterface` (`#[SatisfiesServiceContract]`).

- **What `request()` reveals:** nothing. An unknown address, a disabled account, an account over its limit and a mailer that fails all look like a sent link (a failure is logged, without the token).
- **The link:** build it from the site's configured address, never from the request's `Host` header, and repeat `#[\SensitiveParameter]` on `$token` in the mailer. A queued mail keeps its rendered body, so the live link sits in the mail store until it expires.
- **After a reset:** the account's failed sign-ins and lock are cleared and its other links stop working. Every new password raises `PlatformUser::getCredentialVersion()`, and with it two things end: every session signed in with the old one, on its next request (the OS console, and any session `SessionAuthHandler` reads — the platform user's principal is a `CredentialStampedInterface`), and every link sent before it, however the password was set (the console, `user:password`, another link). Pass `credentialStamp: $principal->credentialStamp()` to `AuthSessionWriter::setAuthenticated()` whenever a sign-in proved the password (or a reset link). Without one, a new identity on the session gets the stamp from the user provider, but the identity the session already holds keeps the stamp it had, so a sign-in that proves nothing (an SSO refresh, remember-me) cannot carry a session across a password change. A password sign-in on a session that still holds the same user with an old stamp, and a password change in your own handler, both need the new stamp passed, or that session ends on its next request.
- **Limits:** `PLATFORM_USER_RESET_TTL_SECONDS` (3600), `PLATFORM_USER_RESET_MAX_REQUESTS` (3) per `PLATFORM_USER_RESET_WINDOW_SECONDS` (900). The limit protects one inbox from floods; it is not a lock. A client asking for many addresses is limited in the action, with `RateLimiter` as above (see [Rate Limiting](../routing/rate-limiting.md)).
- **Schema:** `orm:sync` creates `platform_user_password_reset`.

Settings pages embed `@platform-ui/pages/settings.html.twig`. It takes `sections: [{key, label, href, icon}]` and `current`, and fills `{% block section %}`. The section links sit beside the content, or become a scrolling row in a narrow container. `component('platform.appearance-settings')` lets the visitor choose light, dark or system (the `skin-mode` behavior): it is kept in this browser and applied before paint.

## State on the server

A component keeps state in `#[UiState]` properties. A handler changes them; that is all it has to
do:

```php
#[AsComponent(name: 'shop.counter', template: '@project-layouts-Shop/components/counter.html.twig')]
#[UiPart(name: 'increment', uses: ButtonPrimitive::class)]
final class CounterComponent
{
    #[UiState]
    public int $count = 0;

    #[UiOn(part: 'increment', event: 'click')]
    public function increment(UiInteractionEvent $event): void
    {
        $this->count++;
    }
}
```

- **Where it lives.** In the shared component-state store: the application cache, when it is
  shared across workers (redis, valkey, memcached). Without one the props ride the signed
  contexts inline as before, capped at 4 KB, and `#[UiState]` reads them from there. The key is derived from the visitor's session and the instance id,
  so it is the same on every re-render and no one else can name it. The page's signed contexts
  carry that key (`st`), not the values.
- **What a handler sees.** The properties are set from the state as last saved, in their declared
  types. On the first render, a prop of the same name seeds the state; otherwise the property
  default does. Two events that leave before the first answer is drawn both read the saved
  state, so two quick clicks count twice.
- **What a change does.** When a handler leaves a `#[UiState]` property changed, the component is
  drawn again with it and morphed in place, and the new state is saved. A handler that returns
  its own `rerender` gets the state merged in; its own props win.
- **Large props of a stateless component** (over 1 KB) are kept the same way, instead of being
  copied into every context. Small props still ride the context inline, with no store round trip.
  Up to 64 KB is kept.
- **Expiry.** State lasts twelve hours after the last render. An event on a page whose state has
  expired answers `state_expired`; reload the page.

## Islands: live components

A component that says what it reads is an island. When something it reads is written, it is
drawn again on every open page:

```php
#[AsComponent(name: 'shop.open-orders', template: '@project-layouts-Shop/components/open-orders.html.twig')]
final class OpenOrdersComponent implements UiIslandInterface
{
    public function watches(array $props): array
    {
        return [ResourceMetadata::for(OrderResource::class)->getResourceKey()];
    }
}
```

- **What it watches.** `watches()` returns invalidation scopes. A model's resource key is
  published by every ORM write to the model; a raw write touches one with
  `ScopeInvalidatorInterface`. It is asked per visitor, with the props the instance was drawn
  with, so it can depend on what that visitor may see.
- **How.**
  1. For a signed-in visitor, the island's root gets `data-ui-island`: a signed token naming the
     component, the instance and its props.
  2. The island runtime subscribes the one island feed, `platform-ui.island.feed`, with that
     token, through HUG on the page's KISS stream.
  3. A write re-runs the feed, which draws the component again for that visitor under the same
     instance id.
  4. The page morphs it in place, so focus, a typed value and an open dialog survive.
- **Security.** The token is the feed's only input, so a page cannot ask for another component
  or other props.
- **Limits.**
  - The token lasts twelve hours.
  - Props over 8 KB are not signed: the component is drawn, but not live.
  - A guest sees the island as it was drawn, because the feed is a protected route.

`platform.dashboard` is an island: it watches what the visitor's widgets read. See
[Dashboards](dashboards.md).

## Page blocks

Landing-page sections ship as components: `platform.block-hero`, `-features`, `-stats`, `-pricing`, `-cta`, `-faq` (native `<details name>`) and `-footer`. They are rendered from props alone, laid out by their own width (container queries), and every href they render passes `ui_href()`, so a `javascript:` URL in the data renders no link. See [Page Blocks](page-blocks.md).

## Key mechanisms

- **`#[AsComponent]`** — registers the class as a discoverable component; one instance id per render.
- **`#[UiPart]` + `#[UiOn]`** — the parts the browser acts on, and the component method each event reaches.
- **`UiResponsePatch`** — the one effect vocabulary, the same for a reply and a server push.
- **`#[UiState]`** — component state kept on the server; a change is drawn and saved.
- **`UiIslandInterface`** — a component that is drawn again when what it reads is written.
- **`#[UiUrl]`** — a prop kept in the query string: validated on load, written back after a change.
- **`#[AsCommandSource]`** — server commands for the Ctrl+K palette, filtered by permission.
- **`UiInteractionResult::dispatching()`** — domain events for the application's listeners.

## Why this matters

Components become interactive without a separate API endpoint per component. The handler is a method on the component class, its inputs are signed, and HUG is the one door. Behavior stays co-located with the component rather than scattered across ad hoc endpoints and frontend fetch calls.
