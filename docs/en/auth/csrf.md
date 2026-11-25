---
id: auth/csrf
section: auth
slug: csrf
title: CSRF
summary: Every unsafe request from a browser session, signed in or not, must carry the session's CSRF token; a plain HTML form gets it with csrf_field(), JavaScript with the X-CSRF-Token header.
order: 25
locale: en
status: published
verified_against: 2026.11.24.1203
keywords:
  - csrf_field()
  - csrf_token()
  - X-CSRF-Token
  - XSRF-TOKEN
  - "#[CsrfExempt]"
  - CsrfToken
  - SESSION_COOKIE_DOMAIN
---
# CSRF

The session cookie is `SameSite=Lax`, so a browser leaves it off most requests that another
site starts. Lax is not a guarantee: a page on a sibling subdomain is the same site and still
gets the cookie sent. Semitexa therefore checks every unsafe request (POST, PUT, PATCH, DELETE)
that carries the session cookie, signed in or not, and refuses it unless it proves it came
from your own page. A request without the session cookie is not checked.

## How it works

Each session holds one token (`CsrfToken`, a typed session payload). It is created with the
session and rotated when the session is regenerated at sign-in. `CsrfListener` compares it,
in constant time, with what the request presents:

- **a plain HTML form** presents the `_csrf` field. Write it with `csrf_field()`, which renders
  the hidden input for the request being served:

  ```twig
  <form method="post" action="/account/logout">
      {{ csrf_field() }}
      <button type="submit">Sign out</button>
  </form>
  ```

- **JavaScript** sends the `X-CSRF-Token` header. The token is also in the readable
  `XSRF-TOKEN` cookie; a visitor with no session has no such cookie, and their request is not
  checked. `withCsrf()` from `platform-ui/core` adds the header for you.
  `csrf_token()` returns the raw value when a template needs it, for example in a data
  attribute.
- **Platform UI forms** (`platform.form` with a `submitAction`) carry their own per-submit token
  and need neither of these.

A guest is checked too. A guest session holds state (a cart, a draft, the sign-in form itself),
and a form on another site posting into it is the same attack. From the first release after
`2026.11.25.0522`, a guest gets a session, and its token, with the first response that uses
one: a page that renders `csrf_field()` does, and so does a page that streams. A POST with no
session cookie is not checked, so a guest form without `csrf_field()` works for a new visitor
and is refused with 403 for anyone who has a session; `bin/semitexa lint:templates` refuses
such a form. Up to `2026.11.25.0522` every response sets a session cookie, so such a form is
refused for every visitor after their first page. Do not cache a fragment with `csrf_field()`
inside it: the cached token is the one it was first rendered with, so the form is refused with
403 for every other visitor who has a session. While the session store cannot be read (Redis
down), the answer is 503 with `Retry-After` instead: the token the page carries cannot match
the stand-in session, and the visitor should retry rather than be told it forged the request.
Outside a request (CLI, a test), `csrf_field()` renders nothing.

Only POST, PUT, PATCH and DELETE are checked. Any other method, GET among them, is never
checked, so a route that changes state must not answer GET.

A webhook or machine route opts out with `#[CsrfExempt]` on its payload. The listener honours
it on any payload and reads nothing else, whatever access attribute the payload carries. Put it
only on a route that authenticates with a credential of its own, never on a route a signed-in
browser posts to: that is exactly the request the check protects.

## One session across sibling hosts

`SESSION_COOKIE_DOMAIN` (for example `.example.com`) shares one session across the hosts under
that domain; the [language cookie](../runtime/localization.md) takes the same domain. It also
scopes the readable `XSRF-TOKEN` cookie to that domain, as it does the session cookie. The browser
then sends the session cookie to every host under the domain, and a script on any of them can
read the token from `document.cookie` and send a request that passes the check. Set it only
when every host under the domain is yours and serves no one else's pages or scripts.

Unset, the default, both cookies are host-only. A page on a sibling host still gets the session
cookie sent with a form it posts, since it is the same site, but it cannot read the token, so
the check refuses the request. That holds unless CORS lets that host read your pages: with
`CORS_ALLOW_CREDENTIALS=1` and the host listed in `CORS_ALLOW_ORIGIN`, its script can fetch a
page with the session cookie and read the token from the form.

## Why this matters

Before `csrf_field()`, every handler that rendered a form read the token from the session and
passed it through its resource into the template. One missed handler meant a form that every
signed-in user saw refused. Until guests were checked as well, a guest form without the token
worked in development and was refused only once its author signed in. The helper reads the
session of the request being rendered, so it also carries the new token on a page rendered
right after sign-in.
