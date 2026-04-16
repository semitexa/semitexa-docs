---
id: auth/google
section: auth
slug: google
title: Google Authorization
summary: Authorization is required for demo SSE blocks that keep a long-lived backend connection open.
order: 30
locale: en
status: published
keywords:
  - Authorization is required
  - Google Account
  - session-backed login
  - persistent SSE
---
# Google Authorization

Authorization is required for demo SSE blocks that keep a long-lived backend connection open.

## How it works

The Google OAuth flow begins with a redirect to the authorization URL, stores a CSRF state token in the session, receives the callback code, exchanges it for an access token, fetches the user profile, and writes a typed identity payload into the session. The `#[AsAuthHandler]` on `GoogleSessionAuthHandler` re-hydrates the principal on every subsequent request.

## Why this matters

Long-lived SSE streams opened by anonymous traffic are a resource problem. Gating the stream behind an authenticated session means the connection carries a verified identity, and the server can close the stream cleanly if the session expires.
