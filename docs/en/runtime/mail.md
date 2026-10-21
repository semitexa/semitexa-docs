---
id: runtime/mail
section: runtime
slug: mail
title: Sending Mail
summary: Send mail through MailServiceInterface with inline bodies or Twig templates rendered in the mail's own locale, queue it for the mail:work worker or send it at once, and see how SMTP, idempotency keys, retries, the mail_messages and mail_attempts tables and FakeMailTransport behave.
order: 97
locale: en
status: canonical
verified_against: 2026.10.20.0340
keywords:
  - semitexa/mail
  - MailServiceInterface
  - MailEnvelope
  - MailRecipient
  - MailSendOptions
  - MailSendMode
  - MailDispatchResult
  - AttachmentReference
  - MailTransportRegistry
  - MailTransportInterface
  - MailerConfigResolverInterface
  - SmtpMailTransport
  - FakeMailTransport
  - mail:work
  - MAIL_DRIVER
  - MAIL_QUEUE
---

# Sending Mail

`semitexa/mail` sends outbound mail. You describe a message as a `MailEnvelope` and hand it to `MailServiceInterface::send()`. The service:

1. resolves the mailer settings (driver, server, sender) through `MailerConfigResolverInterface`,
2. renders the subject and bodies from a Twig template, if the envelope names one,
3. stores the message in `mail_messages`,
4. then either publishes the message id to a queue for `mail:work`, or delivers it at once through a transport such as SMTP.

Every delivery attempt is written to `mail_attempts`. The package depends on `semitexa/orm` (the tables), `semitexa/ssr` (Twig), `semitexa/locale` and `semitexa/storage` (attachments kept in storage).

## Sending a message

Inject the contract and call `send()`:

```php
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Mail\Domain\Contract\MailServiceInterface;
use Semitexa\Mail\Domain\Model\MailEnvelope;
use Semitexa\Mail\Domain\Model\MailRecipient;

#[InjectAsReadonly]
protected MailServiceInterface $mail;

// ...

$envelope = new MailEnvelope();
$envelope->tenantId = $tenantId;
$envelope->to = [new MailRecipient('ada@example.com', 'Ada Lovelace')];
$envelope->templateHandle = 'welcome';
$envelope->variables = ['name' => 'Ada'];
$envelope->locale = 'uk';
$envelope->idempotencyKey = 'welcome:' . $userId;

$result = $this->mail->send($envelope);

if (!$result->succeeded()) {
    // $result->status, $result->errorCode, $result->errorMessage
}
```

```php
public function send(MailEnvelope $envelope, ?MailSendOptions $options = null): MailDispatchResult;
```

### `MailEnvelope`

`Semitexa\Mail\Domain\Model\MailEnvelope` is a plain mutable object. Every property has a default.

| Property | Type | Used for |
|---|---|---|
| `to`, `cc`, `bcc` | `list<MailRecipient>` | recipients. Bcc recipients get the mail but are not written into any header |
| `from` | `?MailRecipient` | sender; `null` means `MAIL_FROM_EMAIL` / `MAIL_FROM_NAME` |
| `replyTo` | `?MailRecipient` | `Reply-To`; only its address is kept, the name is dropped. `null` means `MAIL_REPLY_TO` |
| `subject`, `htmlBody`, `textBody` | `?string` | inline content, used when `templateHandle` is `null` |
| `templateHandle` | `?string` | a mail template (see [Templates](#templates-and-rendering)); replaces the three inline fields |
| `variables` | `array` | Twig variables for the template |
| `locale` | `?string` | the locale the template is rendered in |
| `attachments` | `list<AttachmentReference>` | see [Attachments](#attachments) |
| `headers` | `array<string, string>` | extra headers |
| `tenantId` | `?string` | stored on the row; scopes the idempotency key |
| `idempotencyKey` | `?string` | see [Idempotency](#idempotency) |
| `mailerKey` | `?string` | passed to the config resolver on a send, and stored on the row, so the worker resolves the same mailer |
| `tags`, `metadata` | arrays | stored on the row as JSON; nothing else reads them |
| `mailKey` | `string` | not read anywhere |

`MailRecipient` (`email`, `name = null`) is readonly. Its constructor throws `InvalidArgumentException` when the address holds a control character (CR and LF included) or `<` / `>`. A name outside printable ASCII is written as an RFC 2047 encoded-word, so a line break in a name cannot start a new header.

### Send mode

`MailSendOptions` has one property, `mode`, a `MailSendMode`: `Queued` (the default) or `Sync`.

```php
use Semitexa\Mail\Domain\Enum\MailSendMode;
use Semitexa\Mail\Domain\Model\MailSendOptions;

$this->mail->send($envelope, new MailSendOptions(mode: MailSendMode::Sync));
```

A `Queued` send is published to a queue only when the queue can reach another process. When it is the in-process queue (`EVENTS_ASYNC=0`, the default), it is delivered at once, as if it were `Sync`. See [Queueing](#queueing-and-the-mail-worker).

### What `send()` returns

`MailDispatchResult` is readonly: `status` (`MailDispatchStatus`), `messageId`, `errorCode`, `errorMessage`. `succeeded()` is true for `Sent` and `Queued`.

| What happened | `status` | `errorCode` | Row in `mail_messages` |
|---|---|---|---|
| the config resolver threw | `Failed` | `config_error` | none |
| the template failed to render | `Failed` | `render_failed` | none |
| a message with the same idempotency key was already sent | `Sent` | | the existing one; its id is returned |
| a message with the same idempotency key is still on its way (`pending`, `queued`, `sending`) | `Queued` | | the existing one; its id is returned |
| published to the queue | `Queued` | | `queued` |
| publishing threw | `EnqueueFailed` | `queue_unavailable` | `enqueue_failed` |
| delivered now, an attachment could not be loaded | `Failed` | `attachment_missing` | `failed` |
| delivered now, the transport accepted it | `Sent` | | `sent` |
| delivered now, no transport is registered under the driver name | `Failed` | `config_error` | `failed` |
| delivered now, the transport refused it | `Failed` | the transport's code | `failed` |
| delivered now, the transport deferred it, and a worker reads the queue | `Queued` | the transport's code | `deferred`, with the first retry queued |
| delivered now, deferred, and the retry could not be queued | `Failed` | the transport's code | `enqueue_failed` |
| delivered now, deferred, with the in-process queue (`EVENTS_ASYNC=0`) | `Failed` | the transport's code | `deferred` |

`send()` does not catch everything: a database error while saving the row is thrown to the caller.

### Attachments

`AttachmentReference` is readonly:

```php
new AttachmentReference(
    filename: 'invoice.pdf',
    mimeType: 'application/pdf',      // default 'application/octet-stream'
    disposition: 'attachment',        // or 'inline'
    contentId: null,                  // with 'inline', written as Content-ID
    storagePath: 'invoices/42.pdf',   // read through StorageDriverInterface
    inlineContents: null,             // or the bytes themselves
    sizeHint: null,                   // stored, not used
);
```

The contents are loaded when the mail is delivered, not when it is sent. `inlineContents` wins over `storagePath`. Inline contents are stored base64-encoded in the row and may be at most 256 KB (`AttachmentReference::MAX_INLINE_SIZE`). A storage path is read with `StorageDriverInterface::get()`. A reference with neither, a storage file that is not there, or inline contents over the limit fail the message with `attachment_missing`.

A part is written as `Content-Disposition: inline` with a `Content-ID` only when `disposition` is `'inline'` and `contentId` is set; otherwise it is an attachment. The file name is quoted with control characters dropped (a non-ASCII name is also sent RFC 2231-encoded), a MIME type that is not `type/subtype` becomes `application/octet-stream`, and a `Content-ID` keeps only letters, digits and `.@_+=-`.

## Templates and rendering

`TwigMailTemplateRenderer` (the default `MailTemplateRendererInterface`) renders three templates from a module's template directory, `src/modules/<Module>/Application/View/templates/` for a project module:

| File | Required |
|---|---|
| `mail/<handle>.subject.txt.twig` | yes. The result is trimmed |
| `mail/<handle>.body.html.twig` | no |
| `mail/<handle>.body.txt.twig` | no. When it is missing and the HTML body exists, the text body is made from the HTML |

Templates are escaped by their extension: the `.html.twig` body is HTML-escaped, the `.txt.twig` subject and text body are not, so `O'Brien` stays `O'Brien`.

A handle is found like this:

- `@Shop/welcome` names the module: the renderer uses `@Shop/mail/welcome.*`.
- `welcome` first tries `mail/welcome.subject.txt.twig` in Twig's default namespace, then every module the template catalog knows, in the catalog's order (project modules, then packages), under the module name and each of its aliases. The first one that has the subject template provides all three parts.

A missing subject template, or any template that exists and fails, makes `send()` return `render_failed`. A body template that does not exist is simply absent. A body that renders to an empty string is stored as `null`. Twig runs with `strict_variables` off, so a variable you forget to pass renders as an empty string.

The text body made from HTML replaces `<br>`, `</p>`, `</div>`, `</li>` and `</h1>` to `</h6>` with line breaks, strips the tags, decodes entities and collapses three or more line breaks into two.

### Locale

`MailEnvelope::$locale` is honoured. When it is set, the renderer switches the locale context to it for the length of the render and puts the sender's locale back afterwards, even when rendering fails. `trans()`, `trans_choice()` and `locale()` in the templates answer for the mail's locale, not the request's. With no locale, the sender's current locale is used.

The value is matched against the supported locales (the tenant's list resolved for this request, else the configured `LOCALE_SUPPORTED`), ignoring case and treating `_` as `-`: first the full tag, then the primary subtag, and the site's own spelling is returned. `uk_UA` on a site that supports `uk` renders in `uk`. A tag the site does not support is used as given, normalised (`pt_br` becomes `pt-BR`), and the translation catalog falls back on its own. See [Localization](localization.md).

The locale only affects template rendering, which happens inside `send()`. Inline `subject`, `htmlBody` and `textBody` are used as they are. The locale is not stored on the row.

## Queueing and the mail worker

Bodies are rendered and stored before anything is queued. The queue message carries only the message id, as `QueuedMailMessage`:

```json
{"type":"mail","messageId":"…","queuedAt":"…","attempts":0,"maxRetries":3,"retryDelay":60}
```

### Which queue

`MailService` uses the core queue transport, `QueueConfig::defaultTransport()`:

| Setting | Transport |
|---|---|
| `EVENTS_TRANSPORT` set | that one |
| `EVENTS_ASYNC` is `1`, `true` or `yes` | `nats` when it is registered (`NATS_URL` or `NATS_PRIMARY_URL` set and the ledger package installed), else `database` (from `semitexa/orm`). If neither exists, publishing fails and `send()` returns `EnqueueFailed` |
| otherwise | `in-memory` |

The queue name is `MailerConfig::$queue`, which is `MAIL_QUEUE`, default `mail`.

**Without async events the mail is sent at once.** When the transport is the in-process one (`QueueConfig::isProcessLocal()`, an `InMemoryTransport` instance), no worker could read the queue, so a `Queued` send is delivered inside `send()`, in the calling process. In a web request, the request waits for the SMTP conversation. This path never retries (see [Retries](#retries-and-attempts)).

### `mail:work`

```bash
bin/semitexa mail:work [transport] [queue]
```

`transport` defaults to `QueueConfig::defaultTransport()`, `queue` to the mailer config's queue (`MAIL_QUEUE`, `mail` by default), the one `send()` publishes to. The command consumes until it is killed. For each queue message, `MailWorker`:

1. ignores a payload that is not JSON or whose `type` is not `mail` (the queue message is consumed and dropped),
2. loads the row by id, without a tenant scope. A missing row is logged and dropped; a row already `sent` is skipped,
3. sets the row to `sending`, resolves the mailer config with the row's tenant id, loads the attachments,
4. delivers through `MailTransportRegistry::get($config->driver)` and writes a `mail_attempts` row,
5. sets the row to `sent`, or retries, or fails it (see [Retries](#retries-and-attempts)).

It logs what it does to the console. The worker is not a container service; the command builds it from the repositories, the config resolver and `AttachmentResolver`.

### In the worker container

In a project started with `bin/semitexa server:start`, `MailWorkerProcesses` (`#[AsWorkerProcessSource]`) adds one line to `bin/semitexa worker:processes`: `5 mail:work <transport> <queue>`, where the queue is the one the config resolver returns with no tenant and no mailer key. The worker service restarts it 5 seconds after it exits. With the in-process queue it adds nothing.

Only `mail:work` can read a mail queue. If a queued listener or handler uses the same queue name, `worker:processes` runs neither worker and says which queue they share; see [Dispatch configuration](../events/dispatch-configuration.md). The queue name must also be one plain word (`A–Z a–z 0–9 . _ : / = -`), or `WorkerProcess` refuses it and no mail worker runs. See [Workers & Scheduling](../cli/workers-scheduling.md).

## Transports and configuration

### Transports

`MailTransportRegistry` is a static, per-process registry keyed by `MailTransportInterface::key()`:

```php
interface MailTransportInterface
{
    public function key(): string;
    public function deliver(PreparedMailMessage $message, MailerConfig $config): MailTransportResult;
}
```

| Key | Class | Registered | Does |
|---|---|---|---|
| `smtp` | `SmtpMailTransport` | on the first `get()` | talks SMTP to `MAIL_HOST:MAIL_PORT` |
| `null` | `NullMailTransport` | on the first `get()` | sends nothing, reports `Accepted` |
| `fake` | `FakeMailTransport` | only when you register it | records what it is given, reports `Accepted` |

`MailTransportRegistry::register($transport)` adds or replaces one; `get($driver)` throws `InvalidArgumentException` for a key nobody registered; `reset()` empties the registry. To add your own transport, implement `MailTransportInterface` and register an instance in every process that sends or delivers mail.

Inside a sandbox, such as an `ai:observe replay`, `get()` always returns a new `NullMailTransport` and records the attempt with `SandboxGuard::withhold('mail', …)`, so a replayed request never sends mail.

A transport answers with a `MailTransportResult`: `status` (`MailTransportStatus`: `Accepted`, `Rejected`, `Deferred`, `Failed`), `providerMessageId`, `providerStatus`, `errorCode`, `errorMessage`, `providerResponse`.

### SMTP

`SmtpMailTransport` opens a new connection for every message:

- With `MAIL_ENCRYPTION` set to `ssl` it connects with implicit TLS (`ssl://`); with `tls` it connects in plain text and sends `STARTTLS` after `EHLO`. No value (or `none`) is plain text; case and spaces do not matter and `starttls` means `tls`. Any other value is refused with `config_error` before anything is sent, rather than sent in plain text. The server's certificate and host name are verified in both modes; a failed `STARTTLS` handshake fails the attempt instead of carrying on in plain text. `MAIL_VERIFY_PEER=false` turns verification off and accepts self-signed certificates, for a local test server only.
- It sends `EHLO` with this machine's host name when that is a full domain, otherwise with the address literal of its end of the connection (`[10.0.0.5]`), falling back to `HELO`.
- When both `MAIL_USERNAME` and `MAIL_PASSWORD` are set, it authenticates with `AUTH LOGIN`. No other mechanism is supported.
- It sends one `RCPT TO` for every to, cc and bcc address. One refused recipient fails the whole message.
- The message gets a `Message-ID` of `<message id@sender's domain>` (the row's UUID), a `Date`, `MIME-Version`, and `multipart/alternative` when both bodies exist, `multipart/mixed` with attachments. Bodies are quoted-printable UTF-8, attachments base64.
- `MAIL_TIMEOUT` is the connect timeout and the read timeout of each response.

An SMTP error is turned into an error code:

| SMTP reply | `errorCode` | Retried by the worker |
|---|---|---|
| no reply (connection refused, closed, timed out) | `network_error` | yes |
| 421 | `rate_limited` | yes |
| 534, 535 | `auth_error` | no |
| 550–553 | `invalid_recipient` | no |
| any other 4xx (greylisting answers 450, 451) | `provider_4xx` | yes |
| any other 5xx | `provider_5xx` | no |

A 4xx reply or no reply is returned as `Deferred`, a 5xx reply as `Failed`. A failed TLS handshake (an untrusted certificate, a host name that does not match it) is returned as `Failed` with `config_error`, which is not retried, and the message says why. Any other non-SMTP exception inside `deliver()` is returned as `Failed` with `network_error`.

### Configuration

`EnvMailerConfigResolver`, the default `MailerConfigResolverInterface`, reads the process environment with `getenv()` on every call, so a change applies to the next send. It ignores the tenant id and mailer key it is given: every tenant gets the same mailer.

| Key | Default | Used for |
|---|---|---|
| `MAIL_DRIVER` | `smtp` | the transport key |
| `MAIL_HOST` | `127.0.0.1` | SMTP server, and the name its certificate must carry |
| `MAIL_PORT` | `1025` | SMTP port |
| `MAIL_USERNAME` | none | `AUTH LOGIN` user |
| `MAIL_PASSWORD` | none | `AUTH LOGIN` password |
| `MAIL_ENCRYPTION` | none | `tls` or `starttls` (STARTTLS), `ssl` (implicit TLS), any case; any other value is refused |
| `MAIL_VERIFY_PEER` | `true` | `false`, `0`, `no` or `off` stops checking the server's certificate (local test servers only) |
| `MAIL_FROM_EMAIL` | `noreply@localhost` | sender address when the envelope has no `from` |
| `MAIL_FROM_NAME` | none | sender name when the envelope has no `from` |
| `MAIL_REPLY_TO` | none | `Reply-To` when the envelope has no `replyTo` |
| `MAIL_TIMEOUT` | `30` | seconds, see SMTP above |
| `MAIL_QUEUE` | `mail` | the queue mail is published to, and the one the worker container reads |
| `EVENTS_ASYNC`, `EVENTS_TRANSPORT` | `0`, none | the queue transport, see [Which queue](#which-queue) |

For the five keys without a default, an empty value counts as unset. For the others, an empty value is used as it is.

`MailerConfig` also has `apiBaseUrl` and `apiToken`; the env resolver never sets them and no shipped transport reads them.

To choose a mailer per tenant or per `mailerKey`, register your own resolver with `#[SatisfiesServiceContract(of: MailerConfigResolverInterface::class)]` (see [Contracts](../di/contracts.md)). If it gives some tenants their own queue, add an `#[AsWorkerProcessSource]` that runs `mail:work` for those queues too, or their mail stays queued.

## Idempotency

When the envelope has an `idempotencyKey`, `send()` first looks for a message with that key: of the same tenant, or, without a `tenantId`, among the messages with no tenant. The same key is the same mail:

- already `sent`: nothing is rendered or sent, and the result is `Sent` with the existing id;
- still on its way (`pending`, `queued`, `sending`), or `deferred` with a retry on the queue: the result is `Queued` with the existing id. With the in-process queue a `deferred` mail has no retry, and it is sent again instead;
- `failed` with `invalid_recipient` or `provider_5xx` (the server refused it for good): nothing is sent, and the result is `Failed` with the stored error;
- any other `failed`, or `enqueue_failed`: the stored message (its rendered subject and bodies and its stored attachments, not the new envelope's) is queued or sent again under the same id.

`mail_messages` has a unique index on `(tenant_id, idempotency_key)`. When two `send()` calls with the same key race and the second insert is refused, the second one answers from the first one's row as above. The index does not cover mail with no tenant (the database lets any number of `NULL` tenants through), so two tenant-less sends racing with one key can both be stored and sent.

The worker has its own guard: a redelivered queue message whose row is already `sent` is skipped.

## Retries and attempts

The worker retries. A failed attempt is retried when its error code is one of `network_error`, `timeout`, `rate_limited` or `provider_4xx`, or, for an error code the package does not know, when the transport said `Deferred`. It is retried while `attempts < maxRetries`, so one `send()` is delivered at most four times (the first try plus `maxRetries: 3`). Calling `send()` again under the same key after it failed starts a new count.

To retry, the worker sets the row to `deferred` and publishes a copy of the queue message with `attempts + 1`, held back `retryDelay` seconds (60). The database and NATS transports hold it back themselves (`DelayedQueueTransportInterface`; not when wrapped by `dual`), so the worker goes straight on to the next mail; on a transport that cannot, the worker waits before publishing. Both numbers are fixed in `QueuedMailMessage`; nothing configures them. If the retry cannot be published, the row becomes `enqueue_failed` with `queue_unavailable`, and `send()` under its key sends it again.

Any other failure sets the row to `failed` with the error code and message.

A delivery that happens inside `send()` (sync mode, or the in-process queue) is tried once there. A `Deferred` result is handed to the queue as the first retry when a worker reads it, and `send()` returns `Queued` with the error; with the in-process queue (`EVENTS_ASYNC=0`) nothing holds a retry, the row stays `deferred` and the result is `Failed`, and `send()` again under its key sends it again.

Every attempt through a transport writes one `mail_attempts` row: `attempt_no` (the number of earlier attempts plus one), driver, transport status, start and finish time, provider message id and status, provider response and error. A message that fails before the transport is called (config error, missing attachment) gets no attempt row.

### Message statuses

| Status | Meaning |
|---|---|
| `pending` | stored, not yet queued or sent |
| `queued` | published to the queue |
| `sending` | a delivery is in progress |
| `sent` | the transport accepted it |
| `deferred` | a temporary failure, with its retry on the queue (or, with the in-process queue, waiting for `send()` under its key) |
| `failed` | final |
| `enqueue_failed` | publishing to the queue failed; final |

## Tables

Both tables come from ORM resource models; `bin/semitexa orm:sync` creates them, and adds new columns (such as `mailer_key`) on an upgrade. Run it before the new code serves: until then every `send()` fails on the missing column. Both are `#[TenantScoped]` on `tenant_id`. The package reads and writes rows by id through a system (cross-tenant) view; the idempotency lookup is scoped to the tenant, or, for mail with no tenant, reads the rows with no tenant through the system view.

| Table | Holds |
|---|---|
| `mail_messages` | one row per message: status, driver, `mailer_key`, sender, recipients (`to_json`, `cc_json`, `bcc_json`), subject (up to 998 characters), rendered `html_body` and `text_body`, headers, tags, metadata, attachment references (inline contents base64), idempotency key, provider message id, last error, `last_attempt_at`, `created_at`, `updated_at`. Indexes: unique `(tenant_id, idempotency_key)`, `status`, `tenant_id` |
| `mail_attempts` | one row per delivery attempt, indexed on `mail_message_id` |

Nothing deletes rows from either table.

## Commands

| Command | Arguments |
|---|---|
| `mail:work` | `[transport]` (default `QueueConfig::defaultTransport()`), `[queue]` (default `mail`) |

There is no command to list, resend or purge messages. See [mail commands](../reference/commands-mail.md).

## Testing with FakeMailTransport

`FakeMailTransport` keeps every `PreparedMailMessage` it is given and reports `Accepted`. Register it and point `MAIL_DRIVER` at it:

```php
use Semitexa\Mail\Application\Service\FakeMailTransport;
use Semitexa\Mail\Application\Service\MailTransportRegistry;

$mail = new FakeMailTransport();
MailTransportRegistry::register($mail);   // key 'fake'
putenv('MAIL_DRIVER=fake');               // read on every send

// ... run the code that sends ...

$mail->assertSentCount(1);
self::assertSame('Your order', $mail->getLastMessage()?->subject);
```

| Method | Does |
|---|---|
| `getSentMessages()` | every message, in order |
| `getLastMessage()` | the last one, or `null` |
| `count()` | how many |
| `reset()` | forgets them |
| `assertSentCount(int $expected)` | throws `RuntimeException` on a different count |
| `assertNothingSent()` | `assertSentCount(0)` |

A `PreparedMailMessage` has `messageId`, `from`, `replyTo`, `to`, `cc`, `bcc`, `subject`, `htmlBody`, `textBody`, `headers` and `attachments` (`ResolvedAttachment`: filename, MIME type, contents, disposition, content id). The bodies are the rendered ones, so a template test can assert on them.

Points to keep in mind:

- The fake only sees mail delivered in the test's own process: a sync send, or a queued send while the queue is in-process (`EVENTS_ASYNC=0`). With an async queue, a queued mail reaches it only when a `mail:work` in that same process delivers it, so send with `MailSendMode::Sync` where it matters.
- `MailService` still writes to `mail_messages` and `mail_attempts`, so the test needs the database, or its own implementations of `MailRepositoryInterface` and `MailAttemptRepositoryInterface`.
- The registry is static. Put `MAIL_DRIVER` back in `tearDown()`, and call `MailTransportRegistry::reset()` if the next test must not see your transport.

See also [Requests and Fakes](../testing/requests-and-fakes.md).

## Watch out for

- **The default settings send nowhere.** `MAIL_DRIVER` defaults to `smtp` on `127.0.0.1:1025`, and the default stack runs no mail server there, so every send fails with `network_error`. Point `MAIL_HOST` at a server, or set `MAIL_DRIVER` to `null` to drop mail on purpose.
- **Without async events nothing is retried by itself.** With `EVENTS_ASYNC=0` (the default), a queued mail is sent inside `send()`; a temporary failure leaves the row `deferred` until `send()` is called again under its key.
- **A mail can be sent twice.** A worker that dies after the server accepted a message, before it marked the row `sent`, sends it again on redelivery. So does a server that stored the message but whose reply to `DATA` timed out: that counts as `network_error` and is retried. A failing `QUIT` after the server's `250` is not a failure.
- **A retry from `send()` on a transport that cannot hold messages back goes out at once.** The worker waits `retryDelay` before re-publishing; `send()` does not, so behind such a transport (for example `EVENTS_TRANSPORT=dual`) a greylisted mail is tried again immediately and uses up one retry.
- **A row stuck in `pending` or `sending` answers `Queued` for good.** A crash between storing and publishing, or an unknown driver (below), leaves such a row; `send()` under its key then reports it queued and never delivers it. Delete the row, or send under a new key.
- **Turning certificate checks on is new.** A `MAIL_HOST` that is an IP address or an alias the certificate does not name, or a relay with a self-signed certificate, now fails with `config_error`. Point `MAIL_HOST` at the name on the certificate, or set `MAIL_VERIFY_PEER=false` for a local test server.
- **Text templates compiled before the upgrade stay HTML-escaped** until `bin/semitexa cache:clear`: Twig keeps the escaping it compiled with.
- **An unknown `MAIL_DRIVER` fails the mail.** The row becomes `failed` with `config_error`, in `send()` and in the worker; fix the setting and `send()` again under the key.
- **The worker reads the config at delivery time.** A queued mail is delivered with the resolver's answer for its tenant and `mailerKey` as it is when the worker runs, not when it was sent. The sender address and bodies were fixed when it was stored.
- **Large inline attachments fail late.** Inline contents over 256 KB are stored and queued, then fail on delivery with `attachment_missing`. Use a storage path.
- **A mail needs a recipient.** With no `to`, `cc` or `bcc`, `send()` returns `Failed` with `invalid_recipient` and stores nothing.
- **`send()` inside a database transaction publishes before the commit.** The queue message goes out right after the row is written, so a worker may look for the row before it is committed (it then drops the message as not found), and a rollback leaves a queue message for a row that never existed.
- **The tables grow without limit.** Every rendered body and every inline attachment stays in `mail_messages`. Delete old rows yourself, for example from a [scheduled job](scheduler.md).
