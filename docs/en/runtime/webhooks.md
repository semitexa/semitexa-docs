---
id: runtime/webhooks
section: runtime
slug: webhooks
title: Webhooks
summary: Receive signed webhooks on a service route with #[AsWebhookReceiver] and a shared replay store, and send your own through a durable outbox that webhook:work signs, delivers and retries.
order: 70
locale: en
status: canonical
verified_against: 2026.10.20.0340
keywords:
  - "#[AsWebhookReceiver]"
  - WebhookAuthHandler
  - WebhookReplayStoreInterface
  - WebhookPublisherInterface
  - OutboundWebhookMessage
  - WebhookEndpointDefinition
  - webhook:work
  - X-Webhook-Signature
---

# Webhooks

`semitexa/webhooks` covers both directions:

- **Inbound** — a route that accepts a request only when its HMAC-SHA256 signature checks out, plus a replay store so the same delivery is acted on once.
- **Outbound** — `publish()` writes a row to an outbox; the `webhook:work` worker signs it, sends it through the core `HttpClientInterface`, and retries with backoff.

Everything that is not the signature check lives in the database: endpoint definitions (`webhook_endpoint_definitions`), the outbox (`webhook_outbox`), the inbox (`webhook_inbox`), an append-only audit log (`webhook_attempts`) and, if you choose the MySQL replay store, `webhook_replay_keys`.

## Receiving

A receiver is a service payload that also carries `#[AsWebhookReceiver]`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Billing\Application\Payload\Request;

use Semitexa\Authorization\Attribute\AsServicePayload;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Webhooks\Auth\Attribute\AsWebhookReceiver;

#[AsServicePayload(path: '/webhooks/partner', methods: ['POST'], responseWith: ResourceResponse::class)]
#[AsWebhookReceiver(secretRef: 'env:PARTNER_WEBHOOK_SECRET', name: 'partner.events')]
final class PartnerWebhookPayload
{
}
```

`#[AsServicePayload]` makes the route accept only a service principal, so no user session can satisfy it. `#[AsWebhookReceiver]` opts it into `WebhookAuthHandler`, an auth handler at priority 5 that runs before the others:

| Parameter | Default | What it does |
|---|---|---|
| `secretRef` | required | `env:NAME` reads the secret from that environment variable; any other string **is** the secret (tests only) |
| `name` | payload class short name | the receiver's stable identity: `WebhookPrincipal::getId()`, and the service id capability grants are looked up by |
| `verificationMode` | `'hmac-sha256'` | any non-empty string verifies; `''` is refused (an unverified receiver belongs on a public payload) |
| `replayWindowSeconds` | `300` | carried on the attribute, read by nothing |

### The signature

The sender puts two headers on the request:

- `X-Webhook-Signature` — the hex HMAC-SHA256 of the signed string. A short prefix before `=` (`sha256=`, `v1=`) is ignored.
- `X-Webhook-Timestamp` — Unix seconds, required. The signed string is `{timestamp}.{raw body}`, and a timestamp more than 300 seconds from the server's clock is refused, so a captured request cannot be sent again later.

A missing, wrong or stale signature, or a `secretRef` that resolves to nothing, throws `AuthenticationException`: the client gets 401, the handler never runs, and no later auth handler gets a chance to accept the request some other way. A good one authenticates the request as a `WebhookPrincipal` with `endpointKey` (the receiver name), `eventId` (the `X-Webhook-Event-Id` header, if sent) and `tenantId` (the organization tenant resolved for the request, or null).

To sign a request by hand:

```bash
ts=$(date +%s)
body='{"type":"order.paid","id":"evt_1"}'
sig=$(printf '%s.%s' "$ts" "$body" | openssl dgst -sha256 -hmac "$PARTNER_WEBHOOK_SECRET" | sed 's/^.* //')
curl -X POST https://app.example/webhooks/partner \
  -H 'Content-Type: application/json' \
  -H "X-Webhook-Timestamp: $ts" \
  -H "X-Webhook-Signature: sha256=$sig" \
  -d "$body"
```

Secrets are resolved through `WebhookSecretResolverInterface::resolve(string $secretRef, ?string $tenantId)`. The default `EnvWebhookSecretResolver` ignores the tenant. For per-tenant secrets, bind your own implementation; the verifier passes it the tenant of the request.

### Replay protection

A valid signature proves who sent the request, not that it is the first time you see it: a sender's retry, or a captured request sent again, verifies just as well. The handler claims a key in `WebhookReplayStoreInterface` and acts only if the claim is the first:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Billing\Application\Handler\PayloadHandler;

use App\Modules\Billing\Application\Payload\Request\PartnerWebhookPayload;
use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsMutable;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Auth\AuthContextInterface;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Exception\ConflictException;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Lifecycle\CurrentRequestStore;
use Semitexa\Webhooks\Application\Service\Auth\WebhookReplayKeyFactory;
use Semitexa\Webhooks\Auth\Contract\WebhookReplayStoreInterface;
use Semitexa\Webhooks\Auth\WebhookPrincipal;

#[AsPayloadHandler(payload: PartnerWebhookPayload::class, resource: ResourceResponse::class)]
final class PartnerWebhookHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected WebhookReplayStoreInterface $replay;

    #[InjectAsMutable]
    protected AuthContextInterface $auth;

    public function handle(PartnerWebhookPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $request = CurrentRequestStore::get();
        $body = (string) $request?->getContent();
        $principal = $this->auth->getUser();
        $tenantId = $principal instanceof WebhookPrincipal ? $principal->tenantId : null;

        // Key on exactly what the signature covers.
        $signed = hash('sha256', $request?->getHeader('X-Webhook-Timestamp') . '.' . $body);
        $key = WebhookReplayKeyFactory::compose('partner.events', $signed, $tenantId);

        if (!$this->replay->markIfFirstSeen($key, 86400)) {
            throw new ConflictException('Duplicate webhook delivery.');
        }

        $event = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        // act on $event

        return $resource
            ->setHeader('Content-Type', 'application/json')
            ->setContent('{"accepted":true}');
    }
}
```

`markIfFirstSeen($key, $ttlSeconds)` is the atomic check-and-claim; never build it from `seen()` followed by `markSeen()`, which two coroutines can both pass. `WebhookReplayKeyFactory::compose($namespace, $eventId, $tenantId)` returns `tenant:{id}:{namespace}:{eventId}`, or `{namespace}:{eventId}` without a tenant, so two tenants never share a key. `ConflictException` answers 409.

Three stores ship:

| Store | Shared across workers | Expiry |
|---|---|---|
| `InMemoryWebhookReplayStore` (bound by default) | no — a static array per worker process | lazy, on the next touch of the key |
| `MySqlWebhookReplayStore` | yes — `INSERT IGNORE` on the `webhook_replay_keys` primary key | an expired key **keeps blocking** until `cleanupExpired()` deletes it |
| `RedisWebhookReplayStore` | yes — `SET NX EX` | by Redis; a null TTL means one year |

With several Swoole workers the default store misses every duplicate that lands on another worker. Its first use with `APP_ENV=prod` records a boot diagnostic saying so. The shared stores take constructor arguments (`OrmManager`, `RedisConnectionPool`), which the container does not build, so bind one through a class of your own that the container can build:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Billing\Application\Service;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Orm\OrmManager;
use Semitexa\Webhooks\Auth\Contract\WebhookReplayStoreInterface;
use Semitexa\Webhooks\Auth\MySqlWebhookReplayStore;

#[SatisfiesServiceContract(of: WebhookReplayStoreInterface::class)]
final class SharedWebhookReplayStore implements WebhookReplayStoreInterface
{
    #[InjectAsReadonly]
    protected OrmManager $orm;

    private ?MySqlWebhookReplayStore $store = null;

    public function seen(string $key): bool { return $this->store()->seen($key); }
    public function markSeen(string $key): void { $this->store()->markSeen($key); }
    public function markIfFirstSeen(string $key, ?int $ttlSeconds = null): bool { return $this->store()->markIfFirstSeen($key, $ttlSeconds); }
    public function clear(): void { $this->store()->clear(); }
    public function isShared(): bool { return true; }
    public function diagnosticName(): string { return 'mysql'; }

    public function cleanupExpired(?int $limit = null): int
    {
        return $this->store()->cleanupExpired(null, $limit);
    }

    private function store(): MySqlWebhookReplayStore
    {
        return $this->store ??= new MySqlWebhookReplayStore($this->orm);
    }
}
```

Run `bin/semitexa contracts:list` afterwards and check that this class, not `InMemoryWebhookReplayStore`, is the active implementation of `WebhookReplayStoreInterface`. Schedule `cleanupExpired()` yourself: `webhook:cleanup` does not reach a store bound this way (see Watch out).

### The inbox

The package also has a persistent inbox: `InboundWebhookReceiver::receive(InboundWebhookEnvelope $envelope, ?WebhookInboundProcessorInterface $processor)` looks up an `inbound` endpoint definition by key, verifies the signature against that definition's `secretRef` and `verificationMode`, writes a `webhook_inbox` row under a dedupe key, and runs the processor, recording each transition in `webhook_attempts`. The dedupe key is `[tenant:{id}:]provider:{providerKey}:endpoint:{endpointKey}:event:{eventId}` when the request carries `X-Webhook-Event-Id` (or one of a few common provider event-id headers), and otherwise `…:hash:{sha256 of the body}`. Inbox statuses: `received`, `verified`, `rejected_signature`, `processing`, `processed`, `failed`, and `duplicate_ignored` on rows written before 2026-10-21. A request whose signature fails is stored under a key of its own, so a forged request naming a real event id cannot make the genuine delivery look like its duplicate. When the same delivery arrives again, its row counts it (`duplicate_count`, `last_received_at`) and keeps its status, and `receive()` returns it marked `duplicate_ignored`. A repeat of a delivery that `failed` is claimed by one arrival (back to `received`) and, when a processor is passed, processed again, as a provider's retry should be.

In this release the receiver and the services it relies on (`InboundWebhookService`, `InboundDedupeKeyFactory`) carry no container attribute, so they cannot be injected: asking the container for `InboundWebhookReceiver` fails with "Container has no service registered", and so does `webhook:replay:inbound`. Until that changes, receive through `#[AsWebhookReceiver]` and the replay store as shown above. `webhook:show inbox` and `webhook:cleanup` read and purge the inbox table either way.

## Sending

### Register an endpoint

Outbound targets are rows in `webhook_endpoint_definitions`. No command creates them; save one through the repository from your own code:

```php
use Semitexa\Orm\Application\Service\Uuid7;
use Semitexa\Webhooks\Domain\Contract\WebhookEndpointDefinitionRepositoryInterface;
use Semitexa\Webhooks\Domain\Enum\WebhookDirection;
use Semitexa\Webhooks\Domain\Model\WebhookEndpointDefinition;

#[InjectAsReadonly]
protected WebhookEndpointDefinitionRepositoryInterface $endpoints;

$now = new \DateTimeImmutable();
$this->endpoints->save(new WebhookEndpointDefinition(
    id: Uuid7::generate(),
    endpointKey: 'partner.orders',
    direction: WebhookDirection::Outbound,
    providerKey: 'partner',
    enabled: true,
    tenantId: null,
    verificationMode: null,
    signingMode: 'hmac-sha256',
    secretRef: 'env:PARTNER_OUTBOUND_SECRET',
    targetUrl: 'https://hooks.partner.example/orders',
    timeoutSeconds: 10,
    maxAttempts: 8,
    initialBackoffSeconds: 30,
    maxBackoffSeconds: 3600,
    dedupeWindowSeconds: null,
    handlerClass: null,
    defaultHeaders: ['User-Agent' => 'my-app-webhooks/1'],
    metadata: null,
    createdAt: $now,
    updatedAt: $now,
));
```

`endpoint_key` is unique across the whole table (both directions, all tenants), so look a key up with `findByEndpointKey()` before saving it again under a new id. `timeoutSeconds`, `maxAttempts` and both backoff values are taken from this row; a `timeoutSeconds` of 0 or less falls back to `WEBHOOK_TIMEOUT_SECONDS`. `webhook:show endpoints` lists what is registered. `WebhookEndpointRegistryInterface` is a separate in-memory list that neither the publisher nor the worker reads.

### Publish

```php
use Semitexa\Webhooks\Domain\Contract\WebhookPublisherInterface;
use Semitexa\Webhooks\Domain\Model\OutboundWebhookMessage;

#[InjectAsReadonly]
protected WebhookPublisherInterface $webhooks;

$this->webhooks->publish(new OutboundWebhookMessage(
    endpointKey: 'partner.orders',
    eventType: 'order.paid',
    payload: ['order_id' => $orderId, 'total' => $total],
    headers: ['X-Webhook-Event-Id' => "order-paid-{$orderId}", 'X-Webhook-Event-Type' => 'order.paid'],
    idempotencyKey: "order-paid-{$orderId}",
));
```

`publish()` sends nothing. It inserts a `pending` outbox row due now and returns. An unknown or disabled `endpointKey` throws `RuntimeException`. With an `idempotencyKey`, a second publish with the same key for the same endpoint definition matches the existing row instead of adding one (a unique index on `(endpoint_definition_id, idempotency_key)`); without one, every call is a new delivery. `payload` is JSON-encoded as the request body. `eventType` is stored on the row but not sent, so put anything the receiver needs to see into `headers`, as above.

### Delivery: `webhook:work`

`webhook:work` claims one due row at a time, sends it, and records the outcome. In a project started with `bin/semitexa server:start` it is already running: the package declares it with `#[AsWorkerProcessSource]`, and the `worker` container runs it next to `queue:work` and the others (`bin/semitexa worker:processes` lists them; `WORKER_REPLICAS` sets how many worker containers run). Without it, published webhooks stay `pending`.

Each attempt:

1. **Claim.** One `UPDATE` sets the row to `delivering`, takes a lease of `WEBHOOK_LEASE_SECONDS` for this worker id and adds one to `attempt_count`. It picks `pending` or `retry_scheduled` rows that are due, and `delivering` rows whose lease has expired (a worker that died mid-send).
2. **Check the target.** Only `http` and `https` URLs are called, and only when every address the host resolves to is public. Loopback, private, link-local (including `169.254.169.254`) and multicast addresses are refused unless `WEBHOOK_ALLOW_PRIVATE_TARGETS=true`. A refusal fails the delivery at once; a host that does not resolve is retried.
3. **Build the request.** `POST` with `Content-Type: application/json`, then the endpoint's `defaultHeaders`, then the message's `headers`, then the signature; a later header replaces an earlier one spelled the same way (the match is case-sensitive). A header containing a line break fails the delivery permanently.
4. **Sign** when the endpoint has both `signingMode` and `secretRef`: `X-Webhook-Timestamp` is the current time and `X-Webhook-Signature` is `sha256=` plus the HMAC of `{timestamp}.{body}`, the format the receiver above verifies. Every retry is signed afresh, so its timestamp is current.
5. **Send** through `HttpClientInterface` with the endpoint timeout (connect timeout at most 10 s), no redirects, no proxy, the connection pinned to the address checked in step 2, and no retries of its own. The response body is kept for the attempt log, cut at 64 KiB.
6. **Classify.** A guarded `UPDATE` writes the result only while this worker still holds the lease:

| Result | Outcome |
|---|---|
| 2xx | `delivered` |
| 4xx other than 408 and 429 | `failed` at once |
| 408, 429, 5xx, 3xx, no response | `retry_scheduled`, or `failed` once `attempt_count` reaches `max_attempts` |

The wait before a retry is `initialBackoffSeconds × 2^(attempt − 1)`, give or take 25 % jitter, at least 1 s and at most `maxBackoffSeconds`. `Retry-After` is not read.

When the lease has run out before the result is written, another worker may already have claimed the row; this worker discards its result and logs "lost lease". Delivery is therefore at least once: a receiver must expect the same request twice.

`bin/semitexa webhook:show attempts <outbox-id>` shows the history of one delivery; the outcomes appear as `delivery_attempt`, `retry_scheduled`, `failed_permanent_4xx`, `failed_attempts_exhausted`, `failed_blocked_target` and `replayed`.

## Commands

| Command | Arguments and options |
|---|---|
| `webhook:work` | `[worker-id]` (default `webhook-worker-{hostname}-{pid}`), `[poll-interval]` in seconds (default 5) — runs until killed; sleeps the interval only when nothing is due |
| `webhook:show` | `<type>` = `endpoints`, `inbox`, `outbox` or `attempts`; `[id]`; `--status` / `-s`; `--limit` / `-l` (default 20) |
| `webhook:replay:outbound` | `<id>` — resets the outbox row to `pending`, due now, `attempt_count` 0, lease cleared, and logs a `replayed` attempt |
| `webhook:replay:inbound` | `<id>` — meant to re-run an inbox row through the receiver; fails in this release (see The inbox) |
| `webhook:cleanup` | `--dry-run`, `--batch-size` / `-b` (rows per table per run, default 1000, 0 = no limit), `--retention-days` (default `WEBHOOK_RETENTION_DAYS`), `--tenant` |

`webhook:show endpoints [key-or-id]` shows one endpoint in full (the secret reference masked to its last four characters). Without an id, `inbox` lists rows with status `received` and `outbox` rows with status `pending` unless `--status` names another; `attempts` requires an inbox or outbox id.

`webhook:cleanup` deletes rows older than the retention window: outbox rows that are `delivered`, `failed` or `cancelled` and hold no live lease; inbox rows that are `processed`, `failed`, `rejected_signature` or `duplicate_ignored`; and attempts. `--tenant` limits the inbox and outbox to that `tenant_id` and skips attempts, which have no tenant column. Run it from the scheduler or cron; nothing runs it for you.

## Configuration

| Key | Default | Used for |
|---|---|---|
| `WEBHOOK_TIMEOUT_SECONDS` | 30 | request timeout for an endpoint whose `timeoutSeconds` is 0 or less |
| `WEBHOOK_LEASE_SECONDS` | 120 | how long a claimed delivery belongs to one worker |
| `WEBHOOK_RETENTION_DAYS` | 30 | the default window of `webhook:cleanup` |
| `WEBHOOK_ALLOW_PRIVATE_TARGETS` | false | allow loopback, private and link-local targets (a receiver on your own network in development) |
| `WORKER_REPLICAS` | 1 | worker containers, each running its own `webhook:work` |

The variables named by your `secretRef` values hold the secrets. `WEBHOOK_MAX_ATTEMPTS`, `WEBHOOK_BACKOFF_BASE_SECONDS`, `WEBHOOK_BACKOFF_MULTIPLIER` and `WEBHOOK_DEDUPE_WINDOW_SECONDS` are read into `WebhookConfig` but nothing uses them: attempts and backoff come from the endpoint row, the multiplier is always 2.

## Watch out for

- **The default replay store does not span workers.** `InMemoryWebhookReplayStore` is per process; bind a shared one before production.
- **Key replay protection on signed bytes.** `X-Webhook-Event-Id` (and so `WebhookPrincipal::$eventId`) is not covered by the signature: a captured request resent with a new event id verifies and passes a key built from it. Hash the timestamp and body, as in the handler above, or use an id from inside the signed body.
- **The window is 300 seconds, fixed.** A signed request is accepted for 300 seconds either side of the server's clock, so a replay key needs keeping only that long; `replayWindowSeconds` changes nothing.
- **An empty secret refuses every request.** `PARTNER_WEBHOOK_SECRET=` (set but empty) is treated like a missing one: nothing verifies.
- **`secretRef` without `env:` is the secret.** `secretRef: 'PARTNER_WEBHOOK_SECRET'` makes the variable's name the HMAC key.
- **A missing outbound secret stops the delivery.** If the endpoint's `env:` variable is unset or empty, the attempt fails and is retried on the usual backoff until its attempts run out, so setting the secret in time lets it through. An endpoint with a `signingMode` and no `secretRef` fails at once, permanently. The outbound signer reads only `env:` and literal references; a custom `WebhookSecretResolverInterface` applies to receiving only.
- **Keep the timeout well under the lease.** A send that outlasts `WEBHOOK_LEASE_SECONDS` (the timeout plus the DNS lookup) is reclaimed and sent again by another worker.
- **Redirects never deliver.** A 3xx is retried until attempts run out; register the final URL, `https://` included.
- **`webhook:replay:outbound` does not check the status.** A `delivered` row is sent again, and a `delivering` one loses its lease while it is in flight, so two workers send it.
- **`webhook:show` lists shortened ids.** List rows show the first 8 characters, which the replay commands cannot use. A UUIDv7 starts with its timestamp, so rows created within about a minute of each other share that prefix.
- **`webhook:cleanup` does not clean a replay store you bound.** It asks the container for `MySqlWebhookReplayStore` itself, which the container never builds, and reports "no MySQL backing". Since expired MySQL keys keep blocking, call `cleanupExpired()` on a schedule.
- **Sequential delivery.** One worker sends one request at a time, so a slow receiver delays everything behind it. Add `WORKER_REPLICAS` rather than raising timeouts.
- **Tenants share one key space.** `endpoint_key` is unique across the table and lookups by key ignore the tenant; give each tenant's endpoint its own key.
