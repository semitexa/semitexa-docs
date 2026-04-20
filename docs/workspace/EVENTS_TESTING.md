# Testing event-driven (async) handling

This project is set up to test async events with NATS.

## 1. Start the stack (with NATS and worker)

```bash
bin/semitexa server:start
```

Or manually:

```bash
docker compose up -d
```

This starts the **app**, **NATS**, and the **events worker**. The worker runs in a separate container and processes async handlers automatically — you don’t need to run `queue:work` yourself.

## 2. Example: contact form → async notification

- **Sync handler:** `ContactFormHandler` — renders the thank-you page (runs immediately).
- **Async handler:** `ContactFormNotifyHandler` — logs the submission via `LoggerInterface` (e.g. `var/log/app.log`, runs in the background).

When you submit the contact form at **http://localhost:9502/contact**, the response returns immediately; the notify handler is enqueued and runs when a worker processes it.

## 3. Worker (no manual step)

The **worker** container starts with the stack and runs `queue:work` continuously. After you submit the contact form, the async handler is processed automatically. To see worker output:

```bash
docker compose logs -f worker
```

You should see lines like:

```
✅ Async handler executed: Semitexa\Modules\Website\Application\Handler\Request\ContactFormNotifyHandler
```

## 4. Check the log

```bash
cat var/log/app.log
```

You should see JSON lines (one per event), e.g.:

```json
{"level":"info","message":"Contact form submitted","context":{"name":"John","email":"john@example.com","message_preview":"Hello..."},"timestamp":"..."}
```

## Summary

| Step              | Command / action                          |
|-------------------|-------------------------------------------|
| Start everything  | `bin/semitexa server:start` (app + NATS + worker) |
| Trigger async job | Submit form at http://localhost:9502/contact |
| See worker logs   | `docker compose logs -f worker`           |
| See result        | `docker compose exec app cat var/log/app.log` |

To disable async and use in-memory only, set `EVENTS_ASYNC=0` in `.env`.
