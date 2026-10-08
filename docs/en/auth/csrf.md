---
id: auth/csrf
section: auth
slug: csrf
title: CSRF
summary: Every unsafe request from a signed-in browser must carry the session's CSRF token; a plain HTML form gets it with csrf_field(), JavaScript with the X-CSRF-Token header.
order: 25
locale: en
status: published
verified_against: 2026.10.03.1952
keywords:
  - csrf_field()
  - csrf_token()
  - X-CSRF-Token
  - "#[CsrfExempt]"
  - CsrfToken
---
# CSRF

The session cookie is `SameSite=Lax`, so a browser leaves it off most requests that another
site starts. Lax is not a guarantee: a page on a sibling subdomain is the same site and still
gets the cookie sent. Semitexa therefore checks every unsafe request (POST, PUT, PATCH, DELETE)
that carries the session cookie of a signed-in visitor, and refuses it unless it proves it came
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
  `XSRF-TOKEN` cookie, and `withCsrf()` from `platform-ui/core` adds the header for you.
  `csrf_token()` returns the raw value when a template needs it, for example in a data
  attribute.
- **Platform UI forms** (`platform.form` with a `submitAction`) carry their own per-submit token
  and need neither of these.

A guest has nothing to forge, so guest requests are not checked. A webhook or machine route
opts out with `#[CsrfExempt]` on its payload. Outside a request (CLI, a test), `csrf_field()`
renders nothing.

## Why this matters

Before `csrf_field()`, every handler that rendered a form read the token from the session and
passed it through its resource into the template. One missed handler meant a form that every
signed-in user saw refused. The helper reads the session of the request being rendered, so it
also carries the new token on a page rendered right after sign-in.
