---
id: routing/debugging
section: routing
slug: debugging
title: Debugging Routes
summary: Three commands that answer why a route is not matching, what it resolves to, and which template finally renders it.
order: 90
locale: en
status: canonical
keywords:
  - routes:list
  - routes:show
  - dev:graph:route
  - 404
---
# Debugging Routes

Routes are discovered from attributes, so a route that does not work is usually a route that was never discovered, or one that matched something else first. These three commands answer that from the outside, without adding a `dump()` to the hydrator.

## `routes:list` — everything that was discovered

```bash
bin/semitexa routes:list
```

```text
  #     Methods    Path                        Name                     Payload Class                       Module                   Access
  17    GET        /                           SiteHomePayload          Semitexa\Site\...\SiteHomePayload   semitexa-site            public
  59    GET        /                           DemoHomePayload          Semitexa\Demo\...\DemoHomePayload   semitexa-demo            public
  117   GET        /__semitexa/error/404       error.404                Semitexa\Ssr\...\PagePayload        semitexa-ssr             public
```

If your route is not in this list, discovery never saw it — the payload is missing an access attribute, or it lives outside a scanned module. No amount of request debugging will help until it appears here.

Note the `#` column and the repeated `/` paths above: several modules legitimately own the same path, because the tenant that serves the request decides which one applies. The number is the index you pass to `routes:show`.

`--json` gives the same data machine-readably. There are no other options.

## `routes:show` — one route in full

Takes either the index from `routes:list` or the path itself:

```bash
bin/semitexa routes:show 117
bin/semitexa routes:show /__semitexa/error/404
```

```text
/__semitexa/error/404
=====================

Route
  Methods   GET
  Path      /__semitexa/error/404
  Name      error.404
  Access    public

Payload
  Class    Semitexa\Ssr\Application\Payload\Request\DefaultNotFoundPagePayload
  Module   semitexa-ssr
  File     vendor/semitexa/ssr/src/Application/Payload/Request/DefaultNotFoundPagePayload.php

Resource
  Class    Semitexa\Ssr\Application\Resource\Response\DefaultErrorPageResource
  Module   semitexa-ssr
```

The `File` line is the one to read first when two modules define a similar path and you need to know which class actually won.

## `dev:graph:route` — the whole chain including the template

`routes:show` stops at the resource. When the question is "which Twig file renders this", ask the project graph:

```bash
bin/semitexa dev:graph:route --path=/__semitexa/error/404
```

```text
GET /__semitexa/error/404

 Auth: Public (no auth required)
 Name: error.404

Chain
   Payload:  Semitexa\Ssr\Application\Payload\Request\DefaultNotFoundPagePayload
   Handler:  Semitexa\Ssr\Application\Handler\PayloadHandler\DefaultNotFoundPageHandler [sync]
   Resource: Semitexa\Ssr\Application\Resource\Response\DefaultErrorPageResource
   Template: @project-layouts-theme-base/pages/error-page.html.twig
```

The path is an **option**, not an argument: `--path=/pricing`, not `dev:graph:route /pricing`. Pass `--method=POST` for anything other than `GET`, and `--json` to consume the chain programmatically.

## Which one to reach for

| Question | Command |
|---|---|
| Was my route discovered at all? | `routes:list` |
| Two modules define this path — which one wins? | `routes:show <path>` |
| Which handler and template serve this? | `dev:graph:route --path=...` |
| Why does a valid-looking URL 404? | Check the `requirements` regex — see [parameterized routes](parameterized.md) |
