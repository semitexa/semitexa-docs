---
id: runtime/benchmark
section: runtime
slug: benchmark
title: Benchmark Baseline
summary: Requests per second and latency of release 2026.11.05.1529 on four routes, with the machine, the method, the spread between runs, and how to measure your own.
order: 46
locale: en
status: published
verified_against: 2026.11.05.1529
keywords:
  - benchmark
  - performance
  - requests per second
  - latency
  - p99
  - dev:bench
---

# Benchmark Baseline

These are the numbers of one release on one machine. They are here so a later release can be compared with this one, and so you can see how they were made. They are not a claim about your application: a real page with its own queries will behave differently, and only measuring it will tell you how.

## What ran

- **Release:** 2026.11.05.1529, `APP_ENV=prod`, `APP_DEBUG=0`. PHP 8.4.27, Swoole 6.2.3.
- **Server:** 4 workers, `SWOOLE_MAX_REQUEST=10000`, MySQL and Redis on the same host.
- **Machine:** a laptop, Intel Core i7-1185G7 (4 cores, 8 threads), 31 GB of memory, otherwise idle.
- **Load:** `bin/semitexa dev:bench`, on the same machine as the server, so the load generator takes CPU from it.
- **Method:** a closed loop over keep-alive connections, opened 10 ms apart. Each route and level ran 5 windows of 10 seconds after a 2-second warm-up, the defaults but for `--repeat`, and the table shows the median window.

## Results

Requests per second of the median window, with the range across the 5 windows, then latency in milliseconds.

| Route | Connections | req/s | Range | p50 | p95 | p99 |
|---|---|---|---|---|---|---|
| `GET /` | 1 | 193 | 185-194 | 5.1 | 6.0 | 7.2 |
| | 16 | 479 | 371-589 | 11.9 | 84.9 | 108.8 |
| | 64 | 518 | 485-604 | 110.7 | 286.0 | 386.3 |
| `GET /demo/get-started/installation` | 1 | 130 | 128-132 | 7.4 | 10.5 | 11.6 |
| | 16 | 351 | 334-427 | 32.6 | 93.5 | 110.9 |
| | 64 | 421 | 361-438 | 186.8 | 313.0 | 372.8 |
| `GET /demo/data/relations` | 1 | 108 | 107-111 | 9.0 | 10.8 | 13.5 |
| | 16 | 299 | 137-314 | 38.1 | 112.9 | 130.4 |
| | 64 | 385 | 352-396 | 112.3 | 401.8 | 444.8 |
| `GET /demo/api/v1/products` (JSON) | 1 | 922 | 913-927 | 1.0 | 1.5 | 1.8 |
| | 16 | 2862 | 2577-3405 | 4.6 | 14.7 | 15.7 |
| | 64 | 3247 | 3170-3661 | 11.8 | 44.1 | 50.8 |

The three HTML routes render a full page. `/demo/data/relations` runs 5 kinds of query per request. The JSON route answers from the database with no template.

19 of about 504,000 requests failed (0.004%), all at 64 connections. They are requests cut as a worker was replaced: see [Worker recycling](worker-recycling.md).

## How far to trust these

- **One connection is steady:** its windows stay within 5%.
- **Sixteen and more are not as steady.** Most routes stay within a factor of 1.6 between windows. `/demo/data/relations` at 16 connections had one window at 137 req/s against about 300 for the rest, and nothing here explains it.
- **The machine drifts.** With identical code a route has measured about 150 and about 115 req/s at one connection on the same laptop, on different runs. Compare numbers taken side by side, not numbers taken days apart.
- **Each connection opens when the one before has had time.** A burst of connections in the same instant goes to whichever worker wakes first, and measures that rather than the server.
- **It is a closed loop.** A client waits for its answer before sending again, so the server sets the pace. Real traffic does not wait, and this says nothing about behaviour past saturation.
- **Minutes, not days.** For what happens over hours, see the [long-run test](soak-test.md).

## Measure your own

Start the server in production mode, then:

```bash
bin/semitexa dev:bench -r / -r /your/route -c 1,16,64 --repeat=5
```

A JSON route answers 406 to the default `Accept`, so name it. A route bound to a tenant needs its host:

```bash
bin/semitexa dev:bench -r /api/orders --accept=application/json
bin/semitexa dev:bench -r / --host=shop.example.test
```

To compare two versions, alternate them on the same machine and repeat: one before and one after, minutes apart, can show a difference that is only the machine.
