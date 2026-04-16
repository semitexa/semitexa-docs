---
id: get-started/local-domain
section: get-started
slug: local-domain
title: Local Domain
summary: Register .test domains through the built-in local-domain helper instead of relying on ad hoc host setup.
order: 20
locale: en
status: canonical
keywords:
  - TENANCY_BASE_DOMAIN
  - bin/semitexa local-domain:add
  - local-domain:list
  - server:restart
demo_preview: get-started-playbook
recommended_runtime_panels:
  - practical-rules
---
# Local Domain

Serious tenancy work should happen on real local hostnames, not on endless `localhost` tabs.

## Canonical flow

1. Choose one stable `.test` base domain.
2. Register the main host and any tenant hosts through the Semitexa helper.
3. Restart the runtime so DNS, proxy, and environment agree on one shape.
4. Open the real host in the browser.

## Commands

```bash
TENANCY_BASE_DOMAIN=semitexa.test
bin/semitexa local-domain:add semitexa.test
bin/semitexa local-domain:add acme.semitexa.test
bin/semitexa local-domain:list
bin/semitexa server:restart
```

## Rules

- prefer one memorable `.test` base domain per project
- use the CLI helper instead of manual host drift
- restart after meaningful DNS or environment changes
- verify tenant hosts in the browser, not only the raw port

## Why this matters

Tenancy, cookie scope, domain routing, and absolute URL behavior become much easier to reason about when local development already behaves like a real product host.
