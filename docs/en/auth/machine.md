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

Clients send an `Authorization: Bearer {client_id}:{secret}` header. The `MachineAuthHandler` (priority 50) extracts and verifies the credential against the `MachineCredential` store, checks that it has not been revoked, confirms the required scopes are granted, and resolves a `MachinePrincipal` for the request.

## Why this matters

Machine-to-machine auth needs a different shape than session auth: no redirect flow, no cookies, explicit scope boundaries, and an audit trail. A revoked credential takes effect immediately without restarting the server because the check happens at request time against the live credential record.
