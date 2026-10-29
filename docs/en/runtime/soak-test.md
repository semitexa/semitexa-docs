---
id: runtime/soak-test
section: runtime
slug: soak-test
title: Long-Run Test of the Swoole Server
summary: What a 50.8-hour run of release 2026.10.20.0340 under steady production-like load showed about latency over time, errors, worker recycling and memory.
order: 45
locale: en
status: published
verified_against: 2026.10.20.0340
keywords:
  - soak test
  - Swoole
  - memory leak
  - SWOOLE_MAX_REQUEST
  - worker recycling
---

# Long-Run Test of the Swoole Server

A Swoole worker lives for thousands of requests, so a leak or a slow drift that a request-per-process server never shows can build up over hours. To check for that, release 2026.10.20.0340 was run for 50.8 hours under a steady, production-like load, with the server's latency, errors, processes and memory sampled every 30 seconds.

The run was planned for 72 hours. It stopped at 50.8 hours because the host machine was shut down; nothing in the framework stopped it. Every number below covers those 50.8 hours.

## What ran

- **Release:** 2026.10.20.0340, installed as a user installs it, with `APP_ENV=prod`. PHP 8.4.26, Swoole 6.2.3, `SWOOLE_MAX_REQUEST=10000`.
- **Data:** the demo application (`orm:sync`, then `semitexa:demo:seed`), MySQL, Redis and NATS, and the queue worker.
- **Load:** an open loop, so a slow server could not slow the load down and hide it:
  - server-rendered pages 6 per second, API calls 3 per second, writes 0.5 per second, events 0.5 per second (sync, async and queued in turn), and pages with deferred blocks 1 per second;
  - 20 live (KISS) streams at all times, each held for 2 to 10 minutes.
- **Sampling:** a sample, then a 30-second pause, so one every 31.6 seconds on average (never more than 36 apart): 5 799 process and memory samples and 23 196 metrics samples (one per worker).

## Results

| Scenario | Requests | Errors | Dropped | p50 ms | p99 ms | p99, first window | p99, last window |
|---|---:|---:|---:|---:|---:|---:|---:|
| Server-rendered pages | 1 101 047 | 116 | 0 | 11.3 | 36.3 | 37.7 | 35.1 |
| API | 549 143 | 66 | 0 | 4.4 | 10.7 | 11.4 | 10.1 |
| Writes | 91 464 | 6 | 0 | 11.8 | 28.7 | 30.0 | 28.6 |
| Events | 91 463 | 28 | 0 | 16.1 | 2 744.3 | 2 741.4 | 2 742.4 |
| Deferred pages | 182 914 | 252 | 0 | 4 609.4 | 4 618.2 | 4 618.2 | 4 618.0 |

- **2 016 031 requests** from the load generator (2 242 976 served by the server, live streams included). **468 errors, 0.023 %.** Nothing was dropped: the server kept up with the load for the whole run.
- **Latency did not grow.** The p99 of the first and last windows match in every scenario; pages, API calls and writes ended slightly faster than they started.
- **Events and deferred pages** have a high p99 of their own: the sync event runs its handlers inside the request, and a deferred page waits for every one of its blocks. Both are the work itself, and both are the same at the end as at the start.
- **Live streams:** 10 653 held, median 318.7 seconds.

## Where the errors were

526 of the 542 errors in the server's log fell in the 30 seconds before a new worker appeared, that is at a worker recycle after `SWOOLE_MAX_REQUEST` requests: a live stream cut, a deferred block ended before its last part, and 13 HTTP 500 responses on the slowest route (a sync event that runs a long chain of handlers). The application log shows those requests cancelled when the leaving worker's drain ended. A request still running then was given 2 seconds to finish and cancelled after that. Since this run, a leaving worker waits for its in-flight requests longer; see [Worker Memory and Recycling](worker-recycling.md).

The other 16 errors are live streams the server closed.

## Processes

- 180 worker replacements, all at `SWOOLE_MAX_REQUEST`.
- 0 forced terminations, 0 abnormal worker exits, 0 container restarts.
- 0 errors and 0 fatals in the Swoole log and the container output.

## Memory

The leak signal is a process's anonymous memory (RssAnon), not its RSS, which also counts shared pages.

- **Workers:** after its first hour, no worker's anonymous memory grew by more than the noise; most stayed at about 0 MB per hour. The mean was 90 MB per worker for the first ten hours and 53 to 54 MB from the second half of the first day to the end.
- **Master process:** RSS 295.8 MB at the start, 149.6 MB at the end; anonymous memory 76.0 MB down to 25.8 MB.
- **Container:** 451 MiB at the start, up to 709 MiB between hours 4 and 12, about 598 MiB by hour 24, and between 597 and 600 MiB for the last 26 hours.

## In short

Over 50.8 hours and two million requests the server's latency stayed flat, its memory levelled off within the first day and did not grow after it, and every worker was replaced on schedule without a forced kill. The only errors worth naming came at worker recycles, from requests longer than the drain gave them.
