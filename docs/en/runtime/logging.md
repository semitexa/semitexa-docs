---
id: runtime/logging
section: runtime
slug: logging
title: Logging
summary: Write structured log lines through LoggerInterface, choose a file, stderr or both with LOG_SINK and per channel with LOG_CHANNELS, and read them back with logs:app — rotation and levels included.
order: 60
locale: en
status: canonical
verified_against: 2026.10.20.0340
keywords:
  - LoggerInterface
  - StaticLoggerBridge
  - LOG_SINK
  - LOG_CHANNELS
  - LOG_LEVEL
  - LOG_FILE
  - logs:app
---

# Logging

Inject `LoggerInterface`:

```php
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Log\LoggerInterface;

#[InjectAsReadonly]
protected LoggerInterface $logger;

$this->logger->warning('Payment provider slow', ['_channel' => 'billing', 'ms' => 2400, 'order' => 1042]);
```

Levels, lowest first: `debug`, `info`, `notice`, `warning`, `error`, `critical`. Code that has no container to inject from (a static helper, an early boot step) uses `StaticLoggerBridge::warning('billing', 'Payment provider slow', [...])`, which names the channel as its first argument and has `error`, `warning`, `info` and `debug`. Before the container exists, its `error` and `warning` go to PHP's error log as plain text, and its `info` and `debug` are dropped.

## What a line looks like

One JSON object per line:

```json
{"timestamp":"2026-10-21T01:12:09+00:00","level":"warning","message":"Payment provider slow","context":{"ms":2400,"order":1042},"channel":"billing","cid":17}
```

- `_channel` and `_request_id` in the context become the top-level `channel` and `request_id`. Use a channel per area, so a reader can filter by it.
- `cid` is the coroutine id: it ties the lines of one request together for a moment, but ids are reused. With the developer tools (`semitexa/dev`) installed, `process` and `block` link the line to the Observatory's record of the request that wrote it.
- Invalid UTF-8 in a message or context is replaced, not dropped. A context that cannot be encoded at all gives a line saying so, with the original message and level.

Each line is written when it is logged, by the coroutine that logged it. While one coroutine is writing, lines logged by others meanwhile wait in memory and go out with its next write, as one batch.

## Settings

| Variable | Default | |
|---|---|---|
| `LOG_LEVEL` | `info` in dev, `warning` otherwise | lines below it are not written |
| `LOG_SINK` | `file` | `file`, `stderr` or `both` |
| `LOG_CHANNELS` | none | per-channel destinations, see [Routing channels](#routing-channels) |
| `LOG_FILE` | `var/log/app.log` | relative to the project root (a leading `/` is ignored); `logs:app` and the Observatory read it there |
| `LOG_MAX_BYTES` | 33554432 (32 MiB) | when the file passes this it is renamed aside with a timestamp suffix; `0` lets it grow |
| `LOG_RETENTION_DAYS` | 14 | rotated files older than this are removed when the next rotation happens; `0` keeps them |

## Routing channels

`LOG_CHANNELS` sends a channel somewhere other than `LOG_SINK`: comma-separated `channel=route` pairs, where a route is `file` (`LOG_FILE`), `stderr`, `both`, `off` (dropped), or a file of the channel's own under `var/log/` ending in `.log`.

```
LOG_CHANNELS=audit=var/log/audit.log,security=both,sql=off
```

- A channel it does not name, and a line with no channel, go where `LOG_SINK` says. Channel names match exactly, case included.
- A channel's own file is rotated like `LOG_FILE` (`LOG_MAX_BYTES`, `LOG_RETENTION_DAYS`).
- A route that is none of these (a typo such as `of`, a path outside `var/log/`, one with `..`, `var/log/swoole.log`, `var/log/error.log` or `LOG_FILE` itself) is ignored with one warning in the error log per worker, and that channel follows `LOG_SINK`.
- Each file and stderr get their lines in the order they were logged, whichever routes write them.
- `logs:app` and the Observatory read `LOG_FILE` only, not a channel's own file.
- There is no syslog or network route. A log shipper reading stderr (`LOG_SINK=stderr`) or the files is the way to a remote collector.

## In a container: stderr

Container runtimes collect what a process writes to stderr. With `LOG_SINK=stderr` every line goes there, one JSON line per entry, and no file is written: `docker compose logs app`, or your platform's log viewer, shows them, and a log shipper can parse them as JSON. `LOG_SINK=both` writes the file as well.

- Each write (one line, or a batch of lines logged at the same moment) up to 4 KiB arrives whole. A longer one (a large context, a long stack trace, a burst) can interleave with another worker's write.
- If the log collector stops reading, the write waits, and lines logged meanwhile wait in memory behind it. A collector that can fall behind should be set not to block (Docker: `log-opts: mode: non-blocking`).
- PHP errors and the framework's last-resort messages go to `var/log/error.log` outside dev, not to stderr.
- In a console command, the lines appear in your terminal. Queue and scheduler lines are in the `worker` container's logs.
- `bin/semitexa logs:app` and the Observatory (both from `semitexa/dev`) read the file. With `LOG_SINK=stderr` there is none: `logs:app` says so, the Observatory shows no lines.

## Reading the log

```bash
bin/semitexa logs:app                          # last 100 lines of the application log
bin/semitexa logs:app --level=ERROR --since=-1h
bin/semitexa logs:app --grep=billing --json
bin/semitexa logs:app --file=swoole            # the server's own log
bin/semitexa logs:app --list                   # every log file and its size
```

`--around=<timestamp>` shows the lines around a moment, `--context` how many on each side (20); `--lines` is how many to show otherwise. The server's own messages (worker starts, crashes, reloads) go to `SWOOLE_LOG_FILE`, `var/log/swoole.log`, which Swoole rotates by date.

## Watch out for

- **A level below `LOG_LEVEL` costs nothing but is lost.** Production defaults to `warning`: an `info` line that explains a failure will not be there. Set `LOG_LEVEL=info` while you investigate.
- **Two users writing one file.** Rotation renames the file and the next writer creates it. If the server runs as one user and your tests as another, the second may be locked out of the new file; give both write access to `var/log`.
- **Secrets in context.** The context is written as given. Do not log tokens, passwords or full request bodies.
