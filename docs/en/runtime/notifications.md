---
id: runtime/notifications
section: runtime
slug: notifications
title: Notifications
summary: Tell recipients about something once and let semitexa/notifications deliver it over mail, a database inbox or your own channels, in each recipient's locale and honouring their opt-outs, with safe retries, an unread inbox, a retention command and FakeNotifier for tests.
order: 98
locale: en
status: canonical
verified_against: 2026.10.20.0340
keywords:
  - semitexa/notifications
  - NotifierInterface
  - Notifier
  - NotifiableInterface
  - NotificationInterface
  - MailNotificationInterface
  - DatabaseNotificationInterface
  - MandatoryNotificationInterface
  - IdempotentNotificationInterface
  - NotificationChannelInterface
  - "#[AsNotificationChannel]"
  - MailContent
  - NotificationReport
  - NotificationPreferences
  - NotificationInbox
  - FakeNotifier
  - notifications:prune
  - NOTIFICATIONS_RETENTION_DAYS
---

# Notifications

> **Not in a tagged release yet.** `semitexa/notifications` is new on the `develop` branches. Release `2026.10.20.0340`, which this page's stamp names, does not contain it. It needs the next releases of `semitexa/orm` (for `OrmManager::getQueryAdapter()`) and `semitexa/mail` (for the idempotent resends [retrying safely](#retrying-safely) relies on). Neither is tagged yet.

`semitexa/notifications` delivers one notification to one or more recipients over the channels the notification names. Two channels come with the package:

- `mail` hands the message to [`semitexa/mail`](mail.md),
- `database` stores it in the recipient's inbox, the `notifications_inbox` table.

A project adds its own channels, such as a chat bot or an SMS gateway, with `#[AsNotificationChannel]`. Each recipient can switch a channel off for one type of notification or for all of them. The package depends on `semitexa/core`, `semitexa/orm` (the two tables) and `semitexa/mail`.

## The recipient

Whoever receives a notification implements `NotifiableInterface`. Usually that is your user model:

```php
use Semitexa\Notifications\Domain\Contract\NotifiableInterface;

final readonly class Customer implements NotifiableInterface
{
    // ...

    public function notificationRecipientId(): string { return $this->id; }
    public function notificationTenantId(): ?string { return $this->tenantId; }
    public function notificationEmail(): ?string { return $this->email; }
    public function notificationName(): ?string { return $this->displayName; }
    public function notificationLocale(): ?string { return $this->locale; }
}
```

- **The tenant id and the recipient id together identify the recipient.** The inbox and the preferences are keyed by both, because a recipient id alone is not unique across tenants. Each id is at most 64 bytes and cannot start or end with a space.
- **They are compared exactly**, including letter case and accents: `abc`, `ABC` and `abç` are three recipients, on MySQL as well as SQLite. Control characters are not allowed in either id.
- **`null` as the tenant is one shared bucket.** Every recipient with that id and no tenant shares one inbox and one set of preferences, stored under the empty tenant id. In a multi-tenant project, always return the tenant.
- **`notificationLocale()`** is the locale the notification is written in, as the site spells it (`uk`, `en`). See [Locale](#locale).

## The notification

A notification has a stable `type()` and a `via()` that picks the channels for a recipient. For each channel it uses, it also implements that channel's small interface:

```php
use Semitexa\Notifications\Domain\Contract\DatabaseNotificationInterface;
use Semitexa\Notifications\Domain\Contract\MailNotificationInterface;
use Semitexa\Notifications\Domain\Contract\NotifiableInterface;
use Semitexa\Notifications\Domain\Model\MailContent;

final readonly class OrderShipped implements MailNotificationInterface, DatabaseNotificationInterface
{
    public function __construct(public int $orderId) {}

    public function type(): string
    {
        return 'orders.shipped';
    }

    public function via(NotifiableInterface $recipient): array
    {
        return $recipient->notificationEmail() !== null ? ['mail', 'database'] : ['database'];
    }

    public function toMail(NotifiableInterface $recipient): MailContent
    {
        return new MailContent(
            subject: "Order #{$this->orderId} has shipped",
            text: "Your order #{$this->orderId} is on its way.",
            html: "<p>Your order #{$this->orderId} is on its way.</p>",
        );
    }

    public function toDatabase(NotifiableInterface $recipient): array
    {
        return ['order_id' => $this->orderId, 'title' => 'Order shipped'];
    }
}
```

- **`type()`** is stored with every inbox row and matched against preferences, so renaming it is a data change. It starts with a lower-case letter, then uses lower-case letters, digits and `. _ : -`, and is at most 120 characters.
- **Channel names** in `via()` start with a lower-case letter, then use lower-case letters, digits and `. _ -`, and are at most 64 characters. A name of digits only, such as `'42'`, is refused.
- **`MailContent`** needs a subject without line breaks and a plain-text body. The HTML body is optional.
- **`toDatabase()`** returns JSON-plain data: scalars, `null` and arrays of them, at most 64 KB once encoded. An object is refused even when it could be encoded, because the inbox would hand it back as an array of a different shape.

A notification that implements `MandatoryNotificationInterface` ignores preferences on every channel. Use it for what the recipient must hear for their own safety, such as a password change or a new sign-in.

## Sending

Inject `NotifierInterface` and call `send()` with one recipient or any iterable of them:

```php
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Notifications\Domain\Contract\NotifierInterface;

#[InjectAsReadonly]
protected NotifierInterface $notifier;

// ...

$report = $this->notifier->send($customer, new OrderShipped($order->id));

if ($report->hasFailures()) {
    foreach ($report->failed() as $outcome) {
        // $outcome->recipientId, $outcome->channel, $outcome->reason, $outcome->detail
    }
}
```

For each recipient, `send()`:

1. switches the locale context to the recipient's locale,
2. calls `via()` and drops any channel named twice,
3. skips the channels the recipient switched off for this type, unless the notification is mandatory,
4. runs every other channel, catches whatever it throws, and switches the locale back.

`send()` does not throw for a delivery problem. A failing channel, recipient or notification never stops the others, and every recipient-and-channel pair ends up in the returned `NotificationReport` as a `DeliveryOutcome`. There is one exception, described [below](#inside-a-database-transaction).

| `status` | `reason` | When |
|---|---|---|
| `Sent` | — | the channel took it: sent, queued or stored |
| `Sent` | `duplicate` | an [idempotent](#retrying-safely) notification this channel had already delivered |
| `Skipped` | `opted_out` | the recipient switched this channel off |
| `Failed` | `channel_failed` | the channel threw; `detail` holds the message |
| `Failed` | `unknown_channel` | no channel has that name; `detail` lists the registered ones |
| `Failed` | `invalid_channel` | `via()` returned something that is not a valid channel name |
| `Failed` | `channel_unavailable` | the channel class could not be built, or two classes claim its name |
| `Failed` | `preferences_unavailable` | the preferences could not be read, so nothing was sent |
| `Failed` | `invalid_recipient` | an empty, padded or oversized id, a recipient whose methods threw, something that is not a `NotifiableInterface`, or a recipient list that threw while it was being read |
| `Failed` | `invalid_notification` | a malformed `type()`, or a `type()` or `via()` that threw |

`NotificationReport` has `sent()`, `skipped()`, `failed()` and `hasFailures()`. It also has:

- **`outcomeFor($recipientId, $channel, $tenantId = null)`**, which looks up one recipient. Pass the tenant: `null` means the recipient without a tenant, not any tenant.
- **`outcomesInAnyTenant($recipientId, $channel)`**, which returns every outcome for that id, whatever the tenant.

An outcome's `tenantId` is `null` for a recipient without a tenant.

Failures are logged as warnings through the core logger. An entry holds the type, channel, recipient id, tenant id, the error's class and the error's message. The notifier never logs the notification's content itself. The error message is the channel's own text, so a channel should keep content out of its exception messages. A logger that throws is ignored, and the report still carries the failure.

`send()` runs in the caller's process. A large audience is better sent from a queued job than from a request handler.

### Locale

While a recipient's channels run, the locale context is set to `notificationLocale()`. This covers `via()`, `toMail()`, `toDatabase()` and your own channel's code. `trans()` and anything else that reads the locale then answer in the recipient's language, not the sender's. The sender's locale is put back afterwards, also when a channel fails. A recipient with no locale, or a blank one, gets the sender's.

The locale is set exactly as given, so return it as the site spells it: `uk`, not `uk_UA` or `UK`. Mail templates map those spellings onto the site's own; the notifier does not, because that mapping needs `semitexa/locale`, which this package does not depend on. Without `semitexa/locale` installed, there is no locale context to switch and nothing changes.

### Inside a database transaction

`send()` may run inside your own `TransactionManager::run()` or `runWithRetry()`. A deadlock, a lock-wait timeout or a lost connection there means the server has rolled your transaction back. Reporting a failure and carrying on would continue on a transaction that no longer exists.

So, while a transaction is open, `send()` rethrows `DeadlockException`, `LockWaitTimeoutException` and `ConnectionLostException` from any channel. It finds them even when a channel wrapped them. `runWithRetry()` then replays your whole unit, `send()` included, so make the notification [idempotent](#retrying-safely) if it can run twice. Outside a transaction, these three are channel failures like any other, and they are the only exceptions `send()` ever lets through.

What the built-in channels write also belongs to your transaction:

- **`database`:** the inbox row is written through the ORM's query adapter, so it commits or rolls back with your transaction. After a rollback the row is gone, although the report said `Sent`.
- **`mail`:** the `mail_messages` row joins your transaction too, but the queue message is published at once, before your commit. After a rollback the mail is never sent: the worker finds no row and drops the message. A transaction that stays open longer than about 30 seconds after `send()` can lose the mail even when it commits. See the transaction note in [Sending Mail](mail.md#watch-out-for).

Send after the commit when the notification must survive a rollback.

### Retrying safely

A send is not a transaction across channels: after a report with failures, some channels have already delivered. To send again without delivering twice, make the notification idempotent:

```php
use Semitexa\Notifications\Domain\Contract\IdempotentNotificationInterface;

final readonly class OrderShipped implements IdempotentNotificationInterface, MailNotificationInterface, DatabaseNotificationInterface
{
    // ...

    public function idempotencyKey(): string
    {
        return "order-{$this->orderId}-shipped";
    }
}
```

The key names the event, not the attempt, so it must stay the same across retries. It is combined with the type and the recipient, so it only needs to be unique per type. With a key:

- **`mail`** passes a per-recipient key to `MailEnvelope::idempotencyKey`. `semitexa/mail` stores one mail per key, so sending again does not mail the recipient twice, and a mail that failed earlier is retried (see [Idempotency](mail.md#idempotency)). Three parts of that behaviour arrive with the next `semitexa/mail` release and are untagged today: the lookup for a mail without a tenant, the resend of a failed mail, and the answer when two sends of one key race. With an older mail release, a retry under an existing key can fail the `mail` channel: the mail service tries to store a second row, its unique index refuses it, and `send()` there throws. For a recipient without a tenant, the key is not looked up at all, so the mail is stored and sent again.
- **`database`** stores the key with the inbox row. A second row with the same key for the same recipient is not stored, and the outcome is `Sent` with the reason `duplicate`. This also holds when the first row was committed by another request after your own transaction began: the unique index answers, not a lookup your transaction's snapshot might not see.

Retrying a partly failed send is then simply calling `send()` again with the same notification and recipients. Channels that delivered before report `Sent` (or `duplicate`), and the rest try again. A project channel can read `idempotencyKey()` the same way, and throw `NotificationAlreadyDeliveredException` to report a duplicate.

## Built-in channels

### `mail`

The notification must implement `MailNotificationInterface`, and the recipient must have an email address. The channel builds a `MailEnvelope` with:

- the recipient's address and name in `to`,
- the recipient's tenant, with the empty tenant passed as `null`, and the recipient's locale,
- the subject and bodies from `MailContent`,
- the idempotency key of an idempotent notification,
- `notification_type` in the metadata and the `notification` tag.

It calls `MailServiceInterface::send()` with no options, so the mail is stored and queued the way `MailService` sends by default. Inside a transaction, the stored row is part of it (see [Inside a database transaction](#inside-a-database-transaction)). **Sent** therefore means that the mail service accepted the mail. Delivery, retries and the record in `mail_messages` are the mail worker's (see [Sending Mail](mail.md)). A `MailDispatchResult` whose `succeeded()` is `false` fails the channel.

### `database`

The notification must implement `DatabaseNotificationInterface`. The channel stores one row in `notifications_inbox` for the recipient, and `NotificationInbox` reads it back.

The row joins the caller's database transaction (see [Inside a database transaction](#inside-a-database-transaction)).

## Your own channel

Implement `NotificationChannelInterface`, register the class as a service and name the channel:

```php
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Notifications\Attribute\AsNotificationChannel;
use Semitexa\Notifications\Domain\Contract\NotifiableInterface;
use Semitexa\Notifications\Domain\Contract\NotificationChannelInterface;
use Semitexa\Notifications\Domain\Contract\NotificationInterface;

#[AsService]
#[AsNotificationChannel('chat')]
final class ChatNotificationChannel implements NotificationChannelInterface
{
    #[InjectAsReadonly]
    protected ChatClient $chat;

    public function send(NotifiableInterface $recipient, NotificationInterface $notification): void
    {
        if (!$notification instanceof ChatNotificationInterface) {
            throw new \LogicException($notification::class . " names 'chat' but has no chat message.");
        }

        $this->chat->post($recipient->notificationRecipientId(), $notification->toChat($recipient));
    }
}
```

Notifications then name `'chat'` in `via()`. Returning normally means the channel took the notification, and any exception counts as that channel's failure for that recipient. Define the per-channel content interface (`ChatNotificationInterface` above) in your own code.

- **The class must be `#[AsService]`.** The channel is built by the container, so it takes its dependencies through `#[InjectAsReadonly]`. Without `#[AsService]`, every delivery on it fails with `channel_unavailable`.
- **Names follow the channel-name rule** above and are unique. A malformed name, or two project classes with one name, stops the worker when it starts. In a console command or a queue worker, where nothing checks at start, the broken declaration fails only itself: two classes with one name fail every delivery on that name, and the other channels keep working.
- **A project class can take over a built-in name.** A class of yours declared as `mail` or `database` replaces the package's channel of that name.

## Preferences

`NotificationPreferences` stores per-recipient switches in `notifications_preferences`. Every channel is on until it is switched off:

```php
use Semitexa\Notifications\Application\Service\NotificationPreferences;

#[InjectAsReadonly]
protected NotificationPreferences $preferences;

// ...

$this->preferences->set($customer, 'orders.shipped', 'mail', false); // no shipping mail
$this->preferences->set($customer, '*', 'mail', false);              // no mail of any type...
$this->preferences->set($customer, 'billing.invoice', 'mail', true); // ...except invoices

$this->preferences->allows($customer, 'orders.shipped', 'mail');     // false
$this->preferences->forget($customer, '*', 'mail');                  // back to the default
$this->preferences->all($customer);                                  // list<NotificationPreference>, for a settings page
```

The type `'*'` switches a channel for every type. When a recipient has a switch for the exact type as well as a `'*'` switch, the switch for the exact type wins. `allowedChannels($recipient, $type, $channels)` answers for several channels in one query; the notifier asks it once per recipient.

## The inbox

`NotificationInbox` reads what the `database` channel stored:

| Method | Returns |
|---|---|
| `latest($recipient, $limit = 20)` | the newest notifications, read or not, newest first |
| `unread($recipient, $limit = 20)` | the newest unread ones, newest first |
| `countUnread($recipient)` | the number for an unread badge |
| `markRead($id, $recipient)` | `true` when an unread row of this recipient was marked |
| `markAllRead($recipient)` | how many rows were marked |

Each row is a `StoredNotification` with `id`, `type`, `data`, `createdAt`, `readAt` and `isRead()`. Limits are clamped to between 1 and 200.

- **`data` is equivalent to what `toDatabase()` returned, not always identical.** MySQL's JSON type stores object keys in its own order, so the keys of a string-keyed array may come back reordered. Lists keep their order.
- **Newest first is to the second, then by id.** `created_at` has one-second precision. Rows from the same second are ordered by their UUIDv7 id, which follows the millisecond. Rows from the same millisecond have no defined order between them.

Every query is pinned to one recipient: tenant and recipient id, compared exactly. `markRead()` takes the id from the request but marks only a row of the recipient you pass, so an id from someone else's inbox returns `false` and changes nothing. Pass the signed-in user as the recipient, never a recipient built from request input.

## Retention

Inbox rows stay until you delete them. `notifications:prune` deletes rows older than a number of days, read and unread alike, in batches:

```bash
bin/semitexa notifications:prune --days=90
bin/semitexa notifications:prune               # uses NOTIFICATIONS_RETENTION_DAYS
```

| Option / variable | Default | Meaning |
|---|---|---|
| `--days` | `NOTIFICATIONS_RETENTION_DAYS` | delete rows created more than this many days ago; `0` deletes nothing |
| `NOTIFICATIONS_RETENTION_DAYS` | `0` | the default for `--days`; `0` or unset keeps everything |
| `--batch-size` | `1000` | rows deleted per statement |

The command prints a count only. Run it from the [scheduler](scheduler.md) or cron. Preferences are never pruned. A pruned idempotent notification no longer counts as delivered, so a retry after the retention window stores it again.

## Tables

`bin/semitexa orm:sync` creates both tables from the package's resource models.

| Table | Columns | Keys |
|---|---|---|
| `notifications_inbox` | `id` (UUIDv7), `tenant_id`, `recipient_id`, `recipient_key`, `type`, `data` (JSON), `idempotency_key`, `created_at`, `read_at` | (`recipient_key`, `read_at`), (`recipient_key`, `created_at`), (`created_at`), unique (`recipient_key`, `idempotency_key`) |
| `notifications_preferences` | `id`, `tenant_id`, `recipient_id`, `recipient_key`, `type`, `channel`, `enabled`, `updated_at` | unique (`recipient_key`, `type`, `channel`) |

`recipient_key` is the SHA-256 of the tenant id and the recipient id. Rows are found and kept unique by it because it compares exactly. MySQL's default collation would treat `abc` and `ABC`, or `josé` and `jose`, as one value. Every query also matches `tenant_id` and `recipient_id`, which are kept readable. A recipient without a tenant is stored under the empty tenant id, not `NULL`. `idempotency_key` is set only for idempotent notifications, and an empty one never collides.

## Testing with FakeNotifier

Hand a `FakeNotifier` to the code that expects `NotifierInterface`. It records every send and runs no channel:

```php
use Semitexa\Notifications\Application\Service\FakeNotifier;

$notifier = new FakeNotifier();                 // knows 'mail' and 'database'
// $notifier = new FakeNotifier(['mail', 'database', 'chat']);  // and your own channels
// give $notifier to the class under test, run it, then:

$notifier->assertSentTo($customer, OrderShipped::class, fn (OrderShipped $n) => $n->orderId === 42);
$notifier->assertSentTimes(OrderShipped::class, 1);
$notifier->assertNotSent(OrderCancelled::class);
```

| Method | Does |
|---|---|
| `assertSent($class, ?$matches)` | some recipient got one; `$matches` receives the notification and the recipient |
| `assertSentTo($recipient, $class, ?$matches)` | this recipient got one, matched by tenant and recipient id |
| `assertSentTimes($class, $times)` | the number of recipients that got one |
| `assertNotSent($class)`, `assertNothingSent()` | nothing of that class, or nothing at all |
| `sentOf($class)`, `sent()` | the recorded notifications, or every send with its recipient and channels |
| `reset()` | forgets them |

A failed assertion throws `\LogicException`.

The fake's report checks what the real notifier checks, without running anything, and like it never throws. These are reported as failed:

- a `type()` or `via()` that throws,
- a recipient that is not one, or whose id the real notifier refuses,
- a malformed type or channel name,
- a channel not in the list given to the constructor.

Every other channel `via()` names is reported as sent. Preferences are not consulted. A send is recorded for every recipient the real notifier would have tried, meaning a valid recipient whose `via()` answered, whatever its channels' outcome. To test your own channel, call its `send()` directly. To test what the inbox shows, store rows with the `database` channel on a test database.

See also [Requests and Fakes](../testing/requests-and-fakes.md).

## Watch out for

- **"Sent" on `mail` means queued.** The mail service accepted the mail. Whether it reached the recipient is in `mail_messages` and `mail_attempts`.
- **"Sent" inside a transaction means "unless it rolls back".** Both built-in channels write into the caller's transaction, and a deadlock there is rethrown rather than reported.
- **Unreadable preferences mean nothing is sent.** If the preferences cannot be read, for example because the table is missing before `orm:sync`, every channel of that recipient fails with `preferences_unavailable`. Sending what the recipient may have switched off would be worse. Mandatory notifications do not read preferences and still go out.
- **A tenantless recipient shares one bucket.** `null` and `''` are the same tenant.
- **`notifications:prune` deletes unread rows too.** Retention is by age only.
- **There is no live feed yet.** The inbox is read on request. An unread badge that updates by itself needs your own live region over `countUnread()`.
