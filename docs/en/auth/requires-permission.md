---
id: auth/requires-permission
section: auth
slug: requires-permission
title: Requires Permission
summary: Declare one permission slug on the payload and let the framework enforce it before your handler runs.
order: 60
locale: en
status: published
verified_against: 2026.09.19.1020
keywords:
  - "#[RequiresPermission]"
  - 401 Unauthorized
  - 403 Forbidden
  - guard chain
---
# Requires Permission

Declare one permission slug on the payload and let the framework enforce it before your handler runs.

## How it works

Place `#[RequiresPermission('slug')]` on any payload class. The guard chain intercepts every request to that route, resolves the current principal, and checks whether the permission is granted. Guests receive 401, authenticated subjects without the grant receive 403, and subjects with the grant reach the handler normally.

## In templates: `can()`

A page shows only what the visitor may use. `can('slug')` asks the same Authorizer the route
guard asks, for the current request. `can(null)` means "signed in", and `signed_in()` asks
that directly:

```twig
{% if can('content.edit') %}<a ui="button" href="/admin/articles/{{ id }}/edit">Edit</a>{% endif %}
```

Hiding a control is never the check. The route, form action or grid action behind it refuses
on its own. `can()` keeps a page from offering what would be refused, so a handler never has to
compute and pass along `canEdit`-style flags. An action that runs outside a guarded route (a
form submit or a grid action through HUG) checks the same way with
`UiPermissions::allows('slug')`.

## Why this matters

Access control should be declarative. When the permission requirement lives on the payload, it is visible to reviewers alongside the route definition, enforced consistently by the framework without any handler code, and impossible to accidentally skip by forgetting a manual check.
