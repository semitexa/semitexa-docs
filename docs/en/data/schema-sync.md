---
id: data/schema-sync
section: data
slug: schema-sync
title: Schema Sync, Not Migration Churn
summary: Semitexa creates SQL only when the real schema changed, blocks destructive drops by default, and logs the exact DDL plan as SQL and JSON.
order: 30
locale: en
status: canonical
keywords:
  - orm:sync
  - --dry-run
  - --allow-destructive
  - two-phase drop
  - AuditLogger
---
# Schema Sync, Not Migration Churn

Semitexa derives the schema change plan by comparing resource attribute definitions against the live database — no hand-written migration files required.

## How it works

Running `bin/semitexa orm:sync` computes the diff between code and database. Safe operations execute immediately. Destructive operations such as `DROP COLUMN` are separated in the plan and require `--allow-destructive` to execute. A missing column triggers a two-phase flow: the first sync marks it deprecated, and a subsequent sync with the explicit flag performs the drop. Every executed sync writes an audit file as both `.json` and `.sql` to `var/migrations/history/`.

## Why this matters

Teams waste time writing empty or obvious migrations that mirror what the code already says. Blocking destructive drops by default prevents accidental data loss, and the structured audit output gives ops a reviewable record of exactly what SQL ran and when.
