---
id: data/table-extension
section: data
slug: table-extension
title: Shared Table Extension
summary: Two modules can extend one table independently, and the ORM merges the schema without forcing either side to edit the other.
order: 80
locale: en
status: canonical
keywords:
  - "#[FromTable]"
  - SchemaCollector
  - Module isolation
  - "#[Column]"
  - "#[TenantScoped]"
---
# Shared Table Extension

Two `ResourceModel` classes in different modules can target the same physical table with `#[FromTable]`. The `SchemaCollector` merges their column sets into one schema plan.

## How it works

The base module defines the core resource with `#[FromTable('products')]` and declares its columns. A later module creates its own resource, also pointing at `#[FromTable('products')]`, and declares only its additional columns. At sync time, `SchemaCollector` groups all discovered resources by table name and only adds missing columns — it never redefines existing ones. Neither module needs to open or modify the other's class.

## Why this matters

Without shared table extension, a later module either has to edit the original resource class (creating cross-module ownership bleed) or maintain a separate table (creating join complexity). The additive model keeps module boundaries clean and schema evolution safe.
