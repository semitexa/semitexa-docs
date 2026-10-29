---
id: runtime/worker-recycling
section: runtime
slug: worker-recycling
title: Worker Memory and Recycling
summary: How a long-lived Swoole worker is restarted: SWOOLE_MAX_REQUEST, the memory limit, the memory watchdog, and what happens to the requests a leaving worker is still answering.
order: 40
locale: en
status: published
verified_against: 2026.10.15.2225
keywords:
  - SWOOLE_MAX_REQUEST
  - SWOOLE_MAX_WAIT_TIME
  - PHP_MEMORY_LIMIT
  - SWOOLE_MEMORY_WATCHDOG_RATIO
  - SWOOLE_MEMORY_WATCHDOG_INTERVAL
  - SWOOLE_WORKER_NUM
  - WorkerMemoryWatchdog
  - InFlightRequests
---

# Worker Memory and Recycling

A Semitexa worker is a Swoole process that serves thousands of requests and holds every open SSE stream for its whole life. Three settings decide when one is replaced by a fresh process. All three go through the same graceful exit, described at the end of this page.

## The memory limit

`PHP_MEMORY_LIMIT` (scaffold default `512M`) is handed to the server as `php -d memory_limit=…` by `docker-compose.yml`. It is a per-worker limit, not a per-request one, so PHP's usual `128M` is too small: at that size workers die under load, mid-request.

`bin/semitexa system:doctor` reports the configured value (check `server.worker-memory`) and warns below `256M`. It reads `PHP_MEMORY_LIMIT`, not the CLI's own `memory_limit`: a CLI process can run with a different limit from the server, and on one production host the CLI said `128M` while every worker ran with `256M`.

With `SEMITEXA_METRICS_ENABLED=1`, `/metrics` shows the figures of the worker that answered, named by `worker_pid`: `worker_memory_usage`, `worker_memory_limit` and `worker_memory_watchdog_threshold`. Ask a few times to see more than one worker.

## Recycling after N requests

`SWOOLE_MAX_REQUEST` (default `10000`) restarts a worker after that many finished requests. It bounds slow leaks. It does nothing for a worker that stops finishing requests, for example one wedged on a blocking read: that worker never reaches the count.

## The memory watchdog

Every `SWOOLE_MEMORY_WATCHDOG_INTERVAL` seconds (default `10`) a worker compares `memory_get_usage(true)` with `SWOOLE_MEMORY_WATCHDOG_RATIO` of its limit (default `0.8`). Past it, the worker logs what it was holding (memory, coroutine count, where its coroutines are parked) under the `lifecycle` channel and stops itself. The manager starts a replacement and the other workers keep serving. It is not counted as a crash.

Set `SWOOLE_MEMORY_WATCHDOG_RATIO=0` to turn it off. With no memory limit (`-1`) it has nothing to measure against and stays off.

## Run at least two workers

The replacement comes from Swoole's manager, and the other workers serve while it boots. With `SWOOLE_WORKER_NUM=1` there are no others: while the one worker is recycled nothing serves, and every open connection, SSE streams included, is cut. With `SWOOLE_MAX_REQUEST=0` as well, Swoole runs that worker without a manager at all, so a watchdog recycle or a fatal error ends the server process. Docker's restart policy starts the container again, but until it is up requests are refused.

So in production run two or more (the scaffold sets `4`). With `APP_ENV=prod` and one worker, `bin/semitexa server:start` warns before it starts the containers, and `system:doctor` warns in check `server.worker-count`. In development one worker is allowed and doctor only says what it would mean.

## What a leaving worker does with its requests

Whatever started it (the request count, the watchdog, a reload), a leaving worker drains:

- Timers are cleared and background coroutines (subscribers, warm-ups, timer bodies) are cancelled at once, so nothing holds the worker past its exit timeout.
- A request being answered, and any coroutine it spawned, is left to finish for up to nine seconds: one second less than `SWOOLE_MAX_WAIT_TIME` (10 by default, 2 to 300), the time the manager waits before it kills the worker. One still running after that is cancelled and answers 500. It was two seconds of three until October 2026: a long request (a slow report, a sync chain of handlers) was cancelled at every recycle. A stream is cut at once whatever the setting, so only requests hold a leaving worker that long; raise it for routes that run longer, and expect `server:stop` and a reload to wait as long for a request still running. Docker waits `stop_grace_period` (30 s in the project's `docker-compose.yml`) before it kills the container, so a value above about 25 needs that raised too, or the kill lands on the server's own shutdown.
- An SSE stream is cancelled at once: it would never end by itself.

A connection that reaches a worker at the instant it exits can still get an empty reply. That happens inside Swoole, and the client should retry it as any dropped connection.
