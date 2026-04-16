---
id: rendering/assets
section: rendering
slug: assets
title: Asset Pipeline
summary: Declare assets with glob patterns in assets.json — served, versioned, and injected automatically.
order: 60
locale: en
status: published
keywords:
  - assets.json
  - asset_head()
  - asset_body()
  - glob patterns
  - versioning
---
# Asset Pipeline

The asset pipeline expands manifest globs, versions files, and injects them into head or body automatically. Declare assets with glob patterns in `assets.json` and the framework handles the rest.

## How it works

Each module ships an `assets.json` manifest that declares which files to include and where to inject them. The framework resolves glob patterns, generates versioned URLs, and injects the results into the correct document position.

```json
{
  "$schema": "semitexa://asset-manifest/v2",
  "include": [
    { "glob": "css/**/*.css", "inject": "head" },
    { "glob": "js/**/*.js",   "inject": "body" }
  ]
}
```

## Key mechanisms

- **`assets.json`** — the manifest file declaring which files to collect and where to inject them.
- **`asset_head()`** — Twig function that outputs all assets registered for the `head` position.
- **`asset_body()`** — Twig function that outputs all assets registered for the `body` position.
- **Glob patterns** — patterns like `css/**/*.css` expand to all matching files at build or boot time.
- **Versioning** — file content hashes or timestamps are appended to asset URLs for cache-busting.

## Why this matters

Manual `<link>` and `<script>` tags in templates drift out of sync with the actual files. Glob-based manifests remove the per-file registration step and keep injection positions consistent across modules.
