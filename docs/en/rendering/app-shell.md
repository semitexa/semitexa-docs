---
id: rendering/app-shell
section: rendering
slug: app-shell
title: App Shell
summary: A multi-page admin feels like one application by marking which regions change per page — the framework derives the chrome-less variant and ships the client that asks for it.
order: 120
locale: en
status: published
keywords:
  - shell_region()
  - X-Semitexa-Shell
  - SemitexaNavigation
  - registerRegion()
  - data-nav="off"
---
# App Shell

Mark the region that changes per page. The framework sends only that region on a navigation, and ships the client that asks for it.

## The problem

Every console reinvents the same thing: a chrome-less variant of its own layout, a router to fetch it, and the arbitration between "re-render this region" and "replace the working area". Measured on a real console before this existed, `/app/calendar` was 102413 bytes as a document and 7374 as a fragment — **93% of every navigation was the same sidebar being sent to replace itself.**

The fix people reach for is a branch in the layout, which is a second copy of the page's structure. A second copy drifts, silently, in whichever shape gets exercised less.

## How it works

A layout marks its per-page regions on the elements it already has:

```twig
<nav class="sidebar">…</nav>

<main class="content"{{ shell_region('main') }}>
    {{ content|raw }}
</main>
```

That is the whole consumer-facing surface. A request carrying `X-Semitexa-Shell: 1` gets a JSON envelope of those regions plus the title and the page's assets; a request without it gets the document it always got.

**One renderer, two shapes.** The chrome-less variant is *read back out of* the rendered document, never rendered its own way. A direct hit, a bookmark, a crawler and a visitor with no JavaScript get exactly what they got before — from the same route, handler and template — and parity is structural rather than something a test has to keep proving.

## The client

`platform-ui/navigation` ships with the framework and activates itself on any page that marks a region. It intercepts plain same-origin link clicks, fetches the chrome-less shape, and:

- moves `pushState` and the DOM **in one synchronous block**, so the address bar and the markup never disagree;
- judges a history step against the URL it last **committed to**, not against what is on screen — back pressed before a page has landed would otherwise compare the new URL to the page still showing, decide nothing changed, and leave the console under a foreign address;
- takes scroll restoration off the browser, which otherwise restores an offset that belonged to the previous page;
- moves focus into the swapped region and announces the new title through a polite live region — a reload does both for free and a swap does neither;
- re-creates any `<script>` inside arriving markup with **this** document's nonce, because a script parsed out of a fragment is inert until re-created and carries the wrong nonce if it is not;
- hands the URL back to the browser on any failure. A navigation that cannot be completed as a swap becomes an ordinary one.

`data-nav="off"` on a link is the escape hatch: a sign-out, a download, a different app.

## One authority, not two negotiating

A console ends up with two swap layers — one that re-renders a region (a filter, a sort, a page of results) and one that replaces the working area. Left to themselves, both push history, both listen to `popstate`, and **listener order decides correctness**.

A region layer registers with the authority instead of owning history for itself:

```js
window.SemitexaNavigation.registerRegion({
    matches: (url) => url.includes('filter='),
    apply: async (url) => { /* re-render the region */ return true; },
});
```

A region move **wins** over a page move for the same click, because it is the cheaper move. The same authority routes history steps, so the layer needs no `popstate` listener of its own.

## The one thing to get right on the server

One URL now answers THREE bodies — the document, the shell envelope on `X-Semitexa-Shell`, and that page's JSON representation on `Accept: application/json` — so the framework declares `Vary: X-Semitexa-Shell, Accept`. It sets it on every shape, including the document, which is the response that gets cached and the one a cache would otherwise hand the chrome-less JSON to. Naming only one of the two knobs tells a shared cache that the other's variants are interchangeable.

That third body is also why `Accept: application/json` is not how you ask for the shell: on a page route it already means "give me that page's JSON representation", which is a different resource. The shell header alone selects the shape, and the shipped client does not ask for `application/json` — it leaves `Accept` unset, so `fetch` sends its default `*/*` rather than the header that would select the other resource.

## Why this matters

Without this, "make the admin feel like one app" is a project-sized decision: a second layout, a router, a history contract, and a list of traps that each cost a round of debugging — the same list in every project. Marking a region is a declaration. Everything above it is the framework's problem.
