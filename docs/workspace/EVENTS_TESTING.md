# Testing event-driven (async) handling

How to see an event listener run outside the request, through NATS and the queue worker. A new project ships no event example of its own, so the steps below use a small listener you add to one of your modules.

## 1. Turn on the NATS transport

In `.env`:

```dotenv
EVENTS_ASYNC=1
```

Then start (or restart) the stack:

```bash
bin/semitexa server:start
```

With `EVENTS_ASYNC=1`, `server:start` adds `docker-compose.nats.yml`, which starts a `nats` (JetStream) container and points the app at it through `NATS_PRIMARY_URL`. With `EVENTS_ASYNC=0` (the default) events are handled in memory.

## 2. Add an event and a queued listener

An event is a plain class, for example `src/modules/Website/src/Domain/Event/ContactSubmitted.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Website\Domain\Event;

final class ContactSubmitted
{
    public function __construct(
        public readonly string $email,
    ) {
    }
}
```

A listener in `src/modules/Website/src/Application/Handler/DomainListener/LogContactListener.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Website\Application\Handler\DomainListener;

use App\Modules\Website\Domain\Event\ContactSubmitted;
use Semitexa\Core\Log\LoggerInterface;
use Semitexa\Core\Attribute\AsEventListener;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Event\EventExecution;

#[AsEventListener(event: ContactSubmitted::class, execution: EventExecution::Queued)]
final class LogContactListener
{
    #[InjectAsReadonly]
    protected LoggerInterface $logger;

    public function handle(ContactSubmitted $event): void
    {
        $this->logger->info('Contact form submitted', ['email' => $event->email]);
    }
}
```

`execution` is required and has no default: `EventExecution::Sync` runs in the request, `Async` runs later in the same worker, `Queued` goes through the transport to the queue worker.

Dispatch the event from any handler through an injected `Semitexa\Core\Event\EventDispatcherInterface`:

```php
#[InjectAsReadonly]
protected EventDispatcherInterface $eventDispatcher;

// in handle():
$this->eventDispatcher->dispatch(new ContactSubmitted($payload->getEmail()));
```

Restart so the workers discover the new classes: `bin/semitexa server:restart`.

## 3. Run the worker

The default stack has no dedicated worker container. Run the worker in a second terminal:

```bash
bin/semitexa queue:work
```

It processes queued handlers until you stop it. In production, run `php vendor/bin/semitexa queue:work` as its own process (see [DEPLOYMENT.md](DEPLOYMENT.md)).

## 4. Trigger and check

Call the route whose handler dispatches the event. The response returns without waiting for the listener; the worker terminal shows the job, and the application log has the entry:

```bash
bin/semitexa logs:app --grep="Contact form submitted"
```

## Summary

| Step | Command / action |
|------|------------------|
| Enable NATS | `EVENTS_ASYNC=1` in `.env`, then `bin/semitexa server:start` |
| Declare a queued listener | `#[AsEventListener(event: ..., execution: EventExecution::Queued)]` |
| Run the worker | `bin/semitexa queue:work` |
| See the result | `bin/semitexa logs:app` |

To go back to in-memory handling, set `EVENTS_ASYNC=0` in `.env` and restart.

More: the hub pages `events/queued` and `events/dispatch-configuration`.
