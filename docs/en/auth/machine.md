---
id: auth/machine
section: auth
slug: machine
title: Machine Auth
summary: Service-to-service authentication via Bearer tokens — issued from the CLI, scoped per route with #[RequiresScope], expiring, rotatable, revocable and audited.
order: 40
locale: en
status: published
verified_against: 2026.10.20.0340
keywords:
  - MachineAuthHandler
  - Bearer {id}:{secret}
  - MachineCredential
  - scopes
  - RequiresScope
  - api:credential:issue
  - revocation
---
# Machine Auth

Service-to-service authentication via Bearer tokens — scoped, expiring, revocable, and audited.

## How it works

Clients send an `Authorization: Bearer {credential_id}:{secret}` header; the id is the credential's UUID, not its client name. `MachineAuthHandler` in `semitexa/api` reads that header from the live request, splits the token once into id and secret, and verifies it against the `MachineCredential` store without persisting the raw secret. Secret verification delegates to `MachineCredential::verifySecret()` (`password_verify()` under the hood), successful auth updates only usage audit fields such as `lastUsedAt` and `requestCount`, and the resolved `MachinePrincipal` exposes credential identity and scopes without echoing the secret back into application state.

## Why this matters

Machine-to-machine auth needs a different shape than session auth: no redirect flow, no cookies, explicit scope boundaries, and an audit trail. A revoked credential takes effect immediately without restarting the server because the check happens at request time against the live credential record.

## Issue a credential

```bash
bin/semitexa api:credential:issue erp-sync --tenant=acme --scope=orders:read --scope=orders:write --expires-in=90d
```

It prints the token once, as `Authorization: Bearer {id}:{secret}`; only an Argon2id hash of the secret is stored, so a lost token cannot be shown again, only replaced.

| Option | |
|---|---|
| `--scope` | a scope it holds, repeatable. Free-form; the convention is `resource:action`. Matched by exact name, no wildcards |
| `--tenant` | the only tenant it authenticates on; a single-tenant install uses `default` |
| `--all-tenants` | valid on every tenant instead. One of the two is required, so an unset variable in a script cannot issue a credential valid everywhere |
| `--expires-in` | `90d`, `12h` or `30m`; after that it no longer authenticates. Without it, it never expires |
| `--json` | the result, token included, as JSON for a script |

In code, `MachineCredentialIssuer::issue($client, $scopes, $tenantId, $expiresAt)` does the same and returns the credential and its token.

## Require scopes on a route

```php
use Semitexa\Api\Attribute\RequiresScope;
use Semitexa\Authorization\Attribute\AsServicePayload;

#[AsServicePayload(path: '/api/orders', methods: ['POST'])]
#[RequiresScope('orders:write')]
final class CreateOrderPayload
{
    // ...
}
```

The caller must be a machine credential holding every scope named; otherwise 403, with the missing scope in the message. Without a token, or with a wrong, expired or revoked one, the route answers 401, as every service route does. In a handler, `$principal->hasScope('orders:write')` asks the same question.

`#[RequiresScope]` asks what the credential was issued with. `#[RequiresCapability]` on a service payload asks something else: the grants a `ServiceCapabilityProviderInterface` (semitexa/rbac) gives that service. Use scopes to limit what one token may do, capabilities when access is decided by policy in your own code.

## Rotate, revoke, list

```bash
bin/semitexa api:credential:list                       # not revoked (expired ones marked): scopes, tenant, expiry, last use, requests
bin/semitexa api:credential:revoke <id> --rotate       # new secret, printed once; the old one stops working at once
bin/semitexa api:credential:revoke <id>                # revoked: refused from the next request
```

The id is the part of the token before the colon. A request that authenticates writes only the usage columns, so it cannot undo a revoke or rotation made while it ran. A revoked or expired credential is refused even with the right secret, and costs the caller the same time as a wrong secret, so the response time does not tell which ids exist.

## Upgrading

Expiry added the `expires_at` column to `api_machine_credentials`. Run `bin/semitexa orm:sync` (or `bin/semitexa update`) before the new code serves requests: until the column exists, a machine request fails when its use is recorded. Existing credentials get no expiry.

