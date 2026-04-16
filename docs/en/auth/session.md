---
id: auth/session
section: auth
slug: session
title: Session Auth
summary: Google signs the user in, then the session stores the selected demo role and re-hydrates it on every request.
order: 10
locale: en
status: published
keywords:
  - Google OAuth
  - "#[SessionSegment]"
  - AuthResult
  - "#[AsAuthHandler]"
---
# Session Auth

Authenticate once per session — the framework stores identity and re-hydrates it on every request.

## How it works

Google OAuth is the single login path. After the callback completes, the authenticated identity is written into a typed Session Payload. On every subsequent request the auth handler reads that payload back out and reconstructs the principal, so handlers never touch raw session keys.

## Why this matters

Session auth in long-running PHP workers is fragile when state leaks across requests. Semitexa isolates session read/write into the execution-scoped tier so each request gets a clean view, and the typed segment guarantees the shape is always valid.
