---
id: cli/orm-console
section: cli
slug: orm-console
title: ORM Console Toolkit
summary: The ORM ships with a practical CLI surface: status, diff, sync, and seed commands with dry-run safety and SQL plan export.
order: 60
locale: en
status: canonical
keywords:
  - orm:status
  - orm:diff
  - orm:sync
  - orm:seed
  - --output
---
# ORM Console Toolkit

Framework credibility also lives in operations. The ORM CLI should tell you what will change before it changes anything.

## How it works

`orm:status` shows database capabilities and whether the schema is in sync. `orm:diff` lists code-versus-database differences without applying them. `orm:sync --dry-run` builds the execution plan as reviewable output, and `--output` exports it to a SQL file for audit trails. `orm:seed` runs `defaults()` upserts for seedable resources to make local and demo environments reproducible.

## Why this matters

Teams that skip review tooling end up applying schema changes they did not fully understand. Treating `orm:sync --dry-run` as the normal review path — rather than an exotic flag — shortens incident recovery and makes schema changes safe to delegate.
