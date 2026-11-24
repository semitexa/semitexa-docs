---
id: platform/tenancy-queue
section: platform
slug: tenancy-queue
title: Tenants in Queued Jobs
summary: The core queue does not carry the tenant. A Queued handler or listener runs with no tenant, so the job carries the tenant id in its payload and enters that tenant itself.
order: 50
locale: en
status: canonical
verified_against: 2026.11.23.1729
keywords:
  - TenantAwareJobSerializer
  - _tenant envelope
  - queue propagation
  - worker context
  - background isolation
---
# Tenants in Queued Jobs

**The core queue does not carry the tenant.** A `Queued` handler or listener that `queue:work` runs has no session, no signed-in user and no tenant: tenant-scoped code in it sees the default scope, not the tenant of the request that queued the job. Nothing warns about it.

## What the queue carries

`QueueDispatcher` writes the handler, the request and response classes, their payloads, the queuing request's session id and a few plain values of that request (`QueuedOrigin`, used for what the job hands back to that request's visitor) into the message. The tenant is not among them, and `QueueWorker` restores none. A `Queued` handler run in process (`EVENTS_ASYNC=0`) is deliberately run the same way, with no request context, so a handler that works there works behind a queue too.

## Getting the tenant in a queued job

There is no automatic way yet. Do it explicitly:

1. **Carry the tenant id in the payload.** Give the queued request (or event) a `tenantId` field with a public `getTenantId(): ?string` and a public `setTenantId(?string $tenantId)`: a payload travels through its getters and is rebuilt through its public setters, `null` included. A setter that takes `string` only throws on a job queued with no tenant, and the job is retried until it lands in the dead letter. Fill it before dispatching, from `TenantContext::get()?->getTenantId()`. That is `null` when no tenant context is set and `'default'` in the default tenant. The handler below skips the switch for both: `fromResolution()` takes a string, and `'default'` names no tenant to enter. The job then runs in the default scope.
2. **Enter that tenant in the handler**, with the calls `TenantFanout` uses:

```php
use Semitexa\Tenancy\Context\TenantContext;
use Semitexa\Tenancy\Context\TenantContextStore;

public function handle(SendInvoice $request, SendInvoiceResult $response): SendInvoiceResult
{
    $store = TenantContextStore::shared();
    $store->clear(); // set() locks the context inside a coroutine; clear() drops the lock
    $tenantId = $request->getTenantId();
    if ($tenantId !== null && $tenantId !== 'default') {
        $store->set(TenantContext::fromResolution($tenantId, 'queue'));
    }
    try {
        // tenant-scoped work
    } finally {
        $store->clear();
    }

    return $response;
}
```

`fromResolution()` builds a context with the organization layer only. A locale or environment layer the request had is not restored: carry those in the payload too if the job needs them.

The worker also wipes the tenant context after every message, so a job that forgets `clear()` does not leave its tenant to the next one.

For work on a clock, a scheduled job with `per_tenant` gets this done for it: `scheduler:work` enters the run's tenant for the length of `handle()`. See [Scheduler](../runtime/scheduler.md).

## What TenantAwareJobSerializer is

`TenantAwareJobSerializer` is a helper for a package that owns both its dispatcher and its worker. The core queue does not call it. In the framework only media does: `MediaQueueDispatcher` wraps its message with it and `MediaWorker` (`media:work`) unwraps it.

- `wrap(array $payload)` adds a `_tenant` key holding the current context's tenant id, strategy, locale and environment. In the default tenant it adds nothing.
- `unwrap(array $payload)` returns the payload without the key, and the context (or `null`).
- `unwrapAndRestore(array $payload)` also makes that context current through the store's fallback, which is read only outside a coroutine.

A worker that calls `unwrapAndRestore()` resets its per-job state after every message, in a `finally`: `PerRequestStateRegistry::resetAll()` and `CoroutineLocal::endRequest()`, the pair `QueueWorker` and `MediaWorker` end each message with. A message queued in the default tenant carries no `_tenant` key and restores nothing, so without the reset its job runs in the tenant of the message before it.

Wrapping the payload of a `Queued` handler's request with it does nothing useful: nothing on the worker's side unwraps it.

## Why this matters

A job that runs in the default scope reads and writes the wrong data without failing. Until the queue carries the tenant, every queued job that touches tenant-scoped data must name its tenant in the payload and enter it before the first query.
