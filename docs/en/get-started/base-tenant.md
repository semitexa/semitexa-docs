---
id: get-started/base-tenant
section: get-started
slug: base-tenant
title: Base Tenant
summary: Establish one default tenant context early so tenant-aware behavior is visible before the rest of the application grows.
order: 40
locale: en
status: canonical
keywords:
  - tenant
  - tenant context
  - tenant config
  - default tenant
demo_preview: get-started-playbook
---
# Base Tenant

Introduce tenancy with one stable base tenant before expanding into multi-tenant complexity.

## Canonical flow

1. Define the default tenant identity.
2. Make sure the runtime resolves one known tenant cleanly.
3. Verify the tenant context affects branding, locale, and feature decisions consistently.

## What this proves

- tenancy is an explicit runtime boundary
- tenant resolution is not hidden inside controllers
- later tenant-specific behavior grows from a clear starting point

## Why this matters

If the first tenant boundary is vague, the rest of the system will treat tenancy as a scattered conditional instead of a real architectural concept.
