---
id: get-started/installation
section: get-started
slug: installation
title: Installation
summary: Create the project, review the baseline env contract, and bring up the Semitexa runtime the supported way.
order: 10
locale: en
status: canonical
keywords:
  - install.sh
  - bin/semitexa
  - .env.default
  - .env
  - self-test
  - routes:list
demo_preview: get-started-playbook
recommended_runtime_panels:
  - install-checklist
  - boot-verification
---
# Installation

Installation in Semitexa should end with a running runtime and a trustworthy operator shell, not with half-finished setup notes.

## Canonical flow

1. Create the project with the supported installer.
2. Move into the project directory.
3. Review `.env.default` as the shared baseline.
4. Create `.env` only when this machine needs local overrides.
5. Start the runtime with `bin/semitexa server:start`.
6. Verify the runtime with `self-test`, route inspection, and a real browser page load.

## Commands

```bash
curl -fsSLo install.sh https://semitexa.com/install.sh
# verify checksum/signature from release notes before execution
bash install.sh
bash install.sh my-project
cd my-project
bin/semitexa server:start
bin/semitexa self-test
bin/semitexa routes:list --json
```

## What to verify after boot

- the application responds in the browser
- `bin/semitexa self-test` reports a healthy runtime
- route discovery is visible through `routes:list`
- the project shape is inspectable before you start authoring modules

## Why this matters

If the first boot path is ambiguous, every later problem becomes harder to diagnose. Semitexa should feel operationally legible from the first hour.
