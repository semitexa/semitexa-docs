---
id: auth/machine
section: auth
slug: machine
title: Machine Auth
summary: Service-to-service authentication via Bearer tokens — scoped, revocable, and audited.
order: 40
locale: en
status: published
keywords:
  - MachineAuthHandler
  - Bearer {id}:{secret}
  - MachineCredential
  - scopes
  - revocation
---
# Machine Auth

Service-to-service authentication via Bearer tokens — scoped, revocable, and audited.

## How it works

Clients send an `Authorization: Bearer {client_id}:{secret}` header. `MachineAuthHandler` in `semitexa/api` reads that header from the live request, splits the token once into id and secret, and verifies it against the `MachineCredential` store without persisting the raw secret. Secret verification delegates to `MachineCredential::verifySecret()` (`password_verify()` under the hood), successful auth updates only usage audit fields such as `lastUsedAt` and `requestCount`, and the resolved `MachinePrincipal` exposes credential identity and scopes without echoing the secret back into application state.

## Why this matters

Machine-to-machine auth needs a different shape than session auth: no redirect flow, no cookies, explicit scope boundaries, and an audit trail. A revoked credential takes effect immediately without restarting the server because the check happens at request time against the live credential record.
