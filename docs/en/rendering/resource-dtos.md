---
id: rendering/resource-dtos
section: rendering
slug: resource-dtos
title: Resource DTOs
summary: A Resource DTO is the one typed source of presentation data: handlers shape it once, templates consume it everywhere, and no view has to dissect random arrays.
order: 20
locale: en
status: canonical
keywords:
  - "#[AsResource]"
  - HtmlResponse
  - with*() methods
  - typed view data
  - auto render
---
# Resource DTOs

A Resource DTO is the typed presentation boundary between handler code and templates. Real separation means templates receive one explicit response object, not loose arrays and last-minute data surgery.

## How it works

1. The payload declares `responseWith`, so the handler gets a concrete Resource DTO instead of assembling loose arrays.
2. Presentation data is pushed through explicit `with*()` methods before Twig sees anything.
3. The template reads one stable response object instead of reformatting raw business data on its own.

## The rules

- The Resource DTO is the response contract for templates, not a passive bucket for whatever data happened to be nearby.
- Handlers should populate presentation fields deliberately through named `with*()` methods.
- Twig should render data, not reinterpret domain state or normalize arrays on the fly.
- Once the resource is complete, auto-rendering can stay mechanical and reliable.

## Key mechanisms

- **`#[AsResource]`** — declares the template and render handle directly on the response DTO.
- **`with*()` methods** — create one explicit vocabulary for everything the template is allowed to consume.
- **`HtmlResponse`** — provides render context accumulation and automatic template rendering after the handler pipeline.

## Fields prepared by the handler

| Field | Purpose |
|---|---|
| `title` | Page heading already shaped for the template |
| `summary` | Intro copy prepared in the handler, not reconstructed in Twig |
| `highlights` | Structured view data ready for repeated rendering blocks |
| `resultPreviewData` | Nested preview state passed as one explicit resource field |
