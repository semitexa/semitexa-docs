---
id: auth/rbac
section: auth
slug: rbac
title: RBAC
summary: Hybrid RBAC with coarse-grained capabilities, exact permission slugs, and module-owned permission catalogs.
order: 70
locale: en
status: published
verified_against: 2026.09.19.1020
keywords:
  - "#[RequiresCapability]"
  - "#[RequiresPermission]"
  - CapabilityRegistry
  - PermissionProviderInterface
  - role:create
  - role:assign
---
# RBAC

Hybrid RBAC: bitmask-backed capabilities for broad checks, slug permissions for exact business rules, and module-owned catalogs behind one authorizer.

## How it works

Semitexa separates two authorization layers. Capabilities are coarse-grained platform rights stored as bitmasks, checked with `#[RequiresCapability]`. Permission slugs are fine-grained business rules registered by any module implementing `PermissionProviderInterface` and checked with `#[RequiresPermission]`. The `Authorizer` resolves both through the `SubjectGrantResolver` so handlers never touch the grant resolution logic directly.

## Why this matters

A single permission model that forces every module to share one storage schema becomes a coupling problem as the application grows. Separating capabilities from slug permissions lets each module own its permission catalog, extend the authorization surface without modifying core tables, and still share one authorizer that keeps the check consistent across the whole application.

## Stored roles

`semitexa/platform-user` keeps roles in the database and gives them to rbac as one more permission provider (`StoredRolePermissionProvider`). A role is a name and the permission slugs it grants; a user holds any number of roles and gets every slug they grant, on top of what the project's own providers add.

```bash
bin/semitexa role:create curator -p exhibits.read -p exhibits.write --tenant=museum
bin/semitexa role:create auditor -p reports.read --shared
bin/semitexa role:assign curator --email=ada@museum.test
bin/semitexa role:grant curator --tenant=museum -p loans.manage --revoke=exhibits.write
bin/semitexa role:list --email=ada@museum.test
bin/semitexa role:assign curator --email=ada@museum.test --remove
bin/semitexa role:delete curator --tenant=museum
```

- `role:create` must be told whose role it is: `--tenant=<id>` for a site's own, or `--shared` for one every site's users can hold.
- A site's role is given only to that site's users, and grants only on that site: on a request for another site (one session can reach several, by path, query or a cookie on the parent domain), the user has the shared roles and that site's own. On the root site, where data is not filtered by tenant, only the shared roles count. Without tenancy at all (a single-site install, the CLI), the user's own site counts.
- `role:assign` takes the user's site's own role of that name, else the shared one; `--shared` names the shared one when both exist.
- `role:grant` and `role:delete` must be told which role: `--tenant=<id>` or `--shared`.
- Role names are lower-case letters, digits and `. _ -`; permission slugs, granted or revoked, are lower-cased and may also hold `:` (`orders.manage`, `reports:read`).
- Only a semitexa/platform-user account has stored roles; a subject signed in another way gets nothing from them.
- A change is seen on the user's next request: grants are cached for one request only.
- `role:delete` takes the role from everyone who held it.

The tables are `platform_role` and `platform_user_role`; `bin/semitexa orm:sync` creates them. Until it has, stored roles grant nothing and the error log says why, once per worker; permission checks keep working on the other providers.
