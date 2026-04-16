---
id: rendering/reactive-ai
section: rendering
slug: reactive-ai
title: Reactive AI Task
summary: Submit a task and watch the AI pipeline stages reveal one by one as the cron job processes it.
order: 150
locale: en
status: published
keywords:
  - DemoAiTask
  - stage-by-stage
  - "refreshInterval: 2"
  - user-triggered → cron pickup
---
# Reactive AI Task

Submit a task and watch the AI pipeline stages reveal one by one as the cron job processes it. The page stays SSR-first while background AI stages keep advancing on the server, so the sidebar and the live pipeline share one consistent page shell.

## How it works

The user submits text through a form. The handler creates a `DemoAiTask` record with status `pending`. A cron job picks up pending tasks and processes them stage by stage, writing stage results into the task record as JSON. A reactive slot with `refreshInterval: 2` re-renders the pipeline view every two seconds, reading the latest stage results from the task record and showing each completed stage.

## The flow

1. User submits the form — a task record is created with `status: pending`.
2. The cron worker picks up the task and starts advancing through stages.
3. Each stage completion is written to `stage_results` as JSON.
4. The reactive slot refreshes every two seconds, reading the updated stage data and rendering the current pipeline state as HTML.
5. When all stages are complete, the slot shows the finished result.

## Key mechanisms

- **`DemoAiTask`** — the task record that carries status, stages, and stage results.
- **stage-by-stage** — the cron processor advances one stage at a time and writes intermediate results.
- **`refreshInterval: 2`** — the slot checks the server every two seconds for updated stage data.
- **user-triggered → cron pickup** — the form submission creates a task; the cron picks it up without a direct async handoff.

## Why this matters

AI pipeline progress is a case where client-side state simulation is especially misleading — AI stage durations are unpredictable, so any client-side estimation is just fabricated progress. Letting the server own the stage results and reflecting them through a live slot gives the user accurate information throughout the processing run.
