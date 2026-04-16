---
id: get-started/locale-setup
section: get-started
slug: locale-setup
title: Locale Setup
summary: Configure the minimal Locale contract so translations and locale-aware rendering become explicit early.
order: 50
locale: en
status: canonical
keywords:
  - locale
  - supported locales
  - translation catalogs
  - trans()
demo_preview: get-started-playbook
---
# Locale Setup

Semitexa keeps locale as an explicit contract. Even when phase 1 ships English-only documentation, the runtime should still make locale handling visible and deterministic.

## Canonical flow

1. Define the default locale.
2. Declare supported locales.
3. Wire translation catalogs.
4. Verify one translation in Twig so the path is proven.

## What to preserve

- locale resolution remains explicit
- fallback behavior is deterministic
- translation keys do not turn into scattered string guesses

## Why this matters

Multilingual support is easiest to preserve when the first locale setup is explicit, even if only English content is active at the beginning.
