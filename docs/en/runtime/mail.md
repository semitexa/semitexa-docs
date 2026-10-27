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
| `replyTo` | `?MailRecipient` | `Reply-To`, address and name. `null` means `MAIL_REPLY_TO` (an address, no name) |
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

A `Queued` send is published to a queue only when the queue can reach another process. When it is the in-process queue (`EVENTS_ASYNC=0`, the default), it is delivered at once, as if it were `Sync`. See [Queueing](#queueing-and-the-mail-worker). Inside a database transaction either mode waits for the commit and answers `Queued` (see [Watch out for](#watch-out-for)).

### What `send()` returns

`MailDispatchResult` is readonly: `status` (`MailDispatchStatus`), `messageId`, `errorCode`, `errorMessage`. `succeeded()` is true for `Sent` and `Queued`.

| What happened | `status` | `errorCode` | Row in `mail_messages` |
|---|---|---|---|
| the config resolver threw | `Failed` | `config_error` | none |
| the template failed to render | `Failed` | `render_failed` | none |
| a field is longer than its column (subject 998 characters; tenant 64; mailer key 128; template handle, sender, reply-to and idempotency key 255) or not UTF-8; the message names the field | `Failed` | `invalid_message` | none |
| no recipient (`to`, `cc`, `bcc` empty) | `Failed` | `invalid_recipient` | none |
| an inline attachment is over 256 KB | `Failed` | `attachment_missing` | none |
| a message with the same idempotency key was already sent | `Sent` | | the existing one; its id is returned |
| a message with the same idempotency key is still on its way (`queued`, or `pending`/`sending` changed in the last 15 minutes) | `Queued` | | the existing one; its id is returned |
| the same key's row is `pending` or `sending` and unchanged for 15 minutes | as a new send | | the existing one, taken over |
| inside a transaction on the mail's connection: held until the commit | `Queued` | | `pending`; after the commit, as the rows below |
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

The contents are loaded when the mail is delivered, not when it is sent. `inlineContents` wins over `storagePath`. Inline contents are stored base64-encoded in the row; the decoded contents may be at most 256 KB (`AttachmentReference::MAX_INLINE_SIZE`). A storage path is read with `StorageDriverInterface::get()`. Inline contents over the limit are refused by `send()` itself (`Failed`, `attachment_missing`, nothing stored); a reference with neither, or a storage file that is not there, fails the message with `attachment_missing` on delivery.

A part is written as `Content-Disposition: inline` with a `Content-ID` only when `disposition` is `'inline'` and `contentId` is set; otherwise it is an attachment. The file name is quoted with control characters dropped (a non-ASCII name is also sent RFC 2231-encoded), a MIME type that is not `type/subtype` becomes `application/octet-stream`, and a `Content-ID` keeps only letters, digits and `.@_+=-`.

## Templates and rendering

`TwigMailTemplateRenderer` (the default `MailTemplateRendererInterface`) renders three templates from a module's template directory, `src/modules/<Module>/Application/View/templates/` for a project module:

| File | Required |
|---|---|
| `mail/<handle>.subject.txt.twig` | yes. The result is trimmed |
| `mail/<handle>.body.html.twig` | no |
| `mail/<handle>.body.txt.twig` | no. When it is missing and the HTML body exists, the text body is made from the HTML |

Templates are escaped by their extension: the `.html.twig` body is HTML-escaped, the `.txt.twig` subject and text body are not, so `O'Brien` stays `O'Brien`. Each template keeps its own escaping when included, so a `.txt.twig` partial included in the `.html.twig` body prints its variables unescaped into the HTML; `bin/semitexa lint:templates` warns about such an include.

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
- When both `MAIL_USERNAME` and `MAIL_PASSWORD` are set, it authenticates with `AUTH LOGIN`, or with `AUTH PLAIN` when the server's `EHLO` reply (the one after `STARTTLS`, with `tls`) offers PLAIN and not LOGIN. No other mechanism is supported. Without `MAIL_ENCRYPTION` it signs in only to a local relay — `localhost`, `127.0.0.1`, `::1` or a one-word host such as a compose service — and refuses any other host with `config_error`, before the password is sent: set `MAIL_ENCRYPTION`, or `MAIL_ALLOW_PLAINTEXT_AUTH=1` on a network you trust.
- A recipient the server answers `251` (it will forward) counts as accepted.
- It sends one `RCPT TO` for every to, cc and bcc address. A recipient refused for good (a 5xx reply, such as a mistyped address) is skipped: the others get the mail. The message row reads `sent`, and its `error_message` names who did not get the mail (`Not delivered to: …`), with a warning in the log. The attempt is recorded with `provider_status` `sent_partially`, and `provider_response.refused_recipients` lists each refused address with the server's code and reply. When every recipient is refused, the mail fails as that refusal. A temporary refusal (4xx) of any one recipient defers the whole mail, because sending it now would reach the others a second time on the retry. A refusal that says too many recipients (`4.5.3`/`5.5.3` or the words, under any code) fails the whole mail at once with `invalid_recipient`, and it is not retried: every try would hit the same limit. Split the list into several mails.
- An address that is not ASCII: a non-ASCII domain (`ada@приклад.укр`) is sent as its punycode form (`ada@xn--80aikifvh.xn--j1amh`) in `MAIL FROM`, `RCPT TO` and the headers, when the PHP `intl` extension is loaded. What is still not ASCII after that (a local part such as `андрій@`, or any non-ASCII domain without `intl`) needs the server to offer `SMTPUTF8` (RFC 6531): the mail then goes with `MAIL FROM:<…> SMTPUTF8` and UTF-8 headers. A server that does not offer it gets nothing: the mail fails with `config_error` before `MAIL FROM`, and the message names each such address. It is not retried, and a resend with the same idempotency key sends it again once `intl` is loaded or `MAIL_HOST` points at a server that offers `SMTPUTF8`. A refused recipient is named as stored, not in its punycode form. `MailRecipient` refuses an address that is not valid UTF-8.
- The message gets a `Message-ID` of `<message id@sender's domain>` (the row's UUID), a `Date`, `MIME-Version`, and `multipart/alternative` when both bodies exist, `multipart/mixed` with attachments. Bodies are quoted-printable UTF-8, attachments base64.
- `MAIL_TIMEOUT` is the connect timeout and the read timeout of each response, except the reply to the end of the message, which is given four minutes (or `MAIL_TIMEOUT`, if longer): a content filter can take that long, and a queue hands an unacknowledged message to another worker after five (RFC 5321 allows ten). A mail sent at once, inside a request, holds the request that long too. No reply at all then fails the mail with `unknown_transport_error`, and it is not retried: the server may have delivered it, so check before resending it by hand.

An SMTP error is turned into an error code:

| SMTP reply | `errorCode` | Retried by the worker |
|---|---|---|
| no reply (connection refused, closed, timed out), or the connection lost while the message was being sent | `network_error` | yes |
| no reply to the end of a message the server received whole | `unknown_transport_error` | no: it may have been delivered |
| 421 | `rate_limited` | yes |
| 534, 535 | `auth_error` | no |
| 550–553, and "too many recipients" under any code | `invalid_recipient` | no |
| any other 4xx (greylisting answers 450, 451) | `provider_4xx` | yes |
| any other 5xx (except a reply to `STARTTLS`, see below) | `provider_5xx` | no |

A 4xx reply or no reply is returned as `Deferred`, a 5xx reply or no reply to the end of the message as `Failed`. A failed TLS handshake (an untrusted certificate, a host name that does not match it), and a 5xx reply to `STARTTLS` (a server that does not offer it on that port), are returned as `Failed` with `config_error`, which is not retried, and the message says why. Any other non-SMTP exception inside `deliver()` is returned as `Failed` with `network_error`.

### Configuration

`EnvMailerConfigResolver`, the default `MailerConfigResolverInterface`, reads each key on every call, from the process environment first and then the project's `.env` files (`Environment::getEnvValue()`), so a change to the process environment applies to the next send. The `.env` files are read when the process starts: after editing them, restart `mail:work` or the server. It ignores the tenant id and mailer key it is given: every tenant gets the same mailer.

| Key | Default | Used for |
|---|---|---|
| `MAIL_DRIVER` | `smtp` | the transport key |
| `MAIL_HOST` | `127.0.0.1` | SMTP server, and the name its certificate must carry |
| `MAIL_PORT` | `1025` | SMTP port |
| `MAIL_USERNAME` | none | SMTP `AUTH` user |
| `MAIL_PASSWORD` | none | SMTP `AUTH` password |
| `MAIL_ENCRYPTION` | none | `tls` or `starttls` (STARTTLS), `ssl` (implicit TLS), any case; any other value is refused |
| `MAIL_VERIFY_PEER` | `true` | `false`, `0`, `no` or `off` stops checking the server's certificate (local test servers only) |
| `MAIL_FROM_EMAIL` | `noreply@localhost` | sender address when the envelope has no `from` |
| `MAIL_FROM_NAME` | none | sender name when the envelope has no `from` |
| `MAIL_REPLY_TO` | none | `Reply-To` when the envelope has no `replyTo` |
| `MAIL_TIMEOUT` | `30` | seconds, see SMTP above |
| `MAIL_ALLOW_PLAINTEXT_AUTH` | off | `1` lets SMTP sign in without TLS to a host that is not a local relay |
| `MAIL_QUEUE` | `mail` | the queue mail is published to, and the one the worker container reads |
| `MAIL_RETENTION_DAYS` | `0` | days a `sent` or `failed` mail and its attempts are kept. `0` keeps everything. Only `mail:work` deletes (an install that sends in-process never does), after a message, at most once an hour and about two seconds or 10 000 rows at a time, so a large backlog takes hours. It is also the idempotency window: once a mail is deleted, its key sends again |
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

A key lasts as long as its row: with `MAIL_RETENTION_DAYS` set, a key whose mail was deleted sends again.

`mail_messages` has a unique index on `(tenant_id, idempotency_key)`. When two `send()` calls with the same key race and the second insert is refused, the second one answers from the first one's row as above. The index does not cover mail with no tenant (the database lets any number of `NULL` tenants through), so two tenant-less sends racing with one key can both be stored and sent.

The worker has its own guard: a redelivered queue message whose row is already `sent` is skipped.

## Retries and attempts

The worker retries. A failed attempt is retried when its error code is one of `network_error`, `timeout`, `rate_limited` or `provider_4xx`, or, for an error code the package does not know, when the transport said `Deferred`. It is retried while `attempts < maxRetries`, so one `send()` is delivered at most four times (the first try plus `maxRetries: 3`). Calling `send()` again under the same key after it failed starts a new count.

To retry, the worker sets the row to `deferred` and publishes a copy of the queue message with `attempts + 1`, held back `retryDelay` seconds (60). The database and NATS transports hold it back themselves (`DelayedQueueTransportInterface`), and so does `dual` over either of them as its primary, so the worker goes straight on to the next mail; on a transport that cannot, the worker waits before publishing. Both numbers are fixed in `QueuedMailMessage`; nothing configures them. If the retry cannot be published, the row becomes `enqueue_failed` with `queue_unavailable`, and `send()` under its key sends it again.

Any other failure sets the row to `failed` with the error code and message.

A delivery that happens inside `send()` (sync mode, or the in-process queue) is tried once there. A `Deferred` result is handed to the queue as the first retry when a worker reads it, and `send()` returns `Queued` with the error; with the in-process queue (`EVENTS_ASYNC=0`) nothing holds a retry, the row stays `deferred` and the result is `Failed`, and `send()` again under its key sends it again.

Every attempt through a transport writes one `mail_attempts` row: `attempt_no` (the number of earlier attempts plus one; two workers on one redelivered message can both write the same number, which only labels the rows), driver, transport status, start and finish time, provider message id and status, provider response and error. A message that fails before the transport is called (config error, missing attachment) gets no attempt row.

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
| `mail_messages` | one row per message: status, driver, `mailer_key`, `reply_to` and `reply_to_name`, sender, recipients (`to_json`, `cc_json`, `bcc_json`), subject (up to 998 characters), rendered `html_body` and `text_body`, headers, tags, metadata, attachment references (inline contents base64), idempotency key, provider message id, last error, `last_attempt_at`, `created_at`, `updated_at`. Indexes: unique `(tenant_id, idempotency_key)`, `status`, `tenant_id` |
| `mail_attempts` | one row per delivery attempt, indexed on `mail_message_id` |

Rows are kept unless `MAIL_RETENTION_DAYS` is set; then `mail:work` deletes `sent` and `failed` messages last changed longer ago than that, with their attempts. `pending`, `queued`, `sending`, `deferred` and `enqueue_failed` rows are never deleted.

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

- The fake only sees mail delivered in the test's own process: a sync send, or a queued send while the queue is in-process (`EVENTS_ASYNC=0`), and only once its transaction commits. Under `RollsBackDatabase` nothing commits, so the fake sees nothing: assert on the stored row. With an async queue, a queued mail reaches it only when a `mail:work` in that same process delivers it, so send with `MailSendMode::Sync` where it matters.
- `MailService` still writes to `mail_messages` and `mail_attempts`, so the test needs the database, or its own implementations of `MailRepositoryInterface` and `MailAttemptRepositoryInterface`.
- The registry is static. Put `MAIL_DRIVER` back in `tearDown()`, and call `MailTransportRegistry::reset()` if the next test must not see your transport.

See also [Requests and Fakes](../testing/requests-and-fakes.md).

## Watch out for

- **The default settings send nowhere.** `MAIL_DRIVER` defaults to `smtp` on `127.0.0.1:1025`, and the default stack runs no mail server there, so every send fails with `network_error`. Point `MAIL_HOST` at a server, or set `MAIL_DRIVER` to `null` to drop mail on purpose.
- **Without async events nothing is retried by itself.** With `EVENTS_ASYNC=0` (the default), a queued mail is sent inside `send()`; a temporary failure leaves the row `deferred` until `send()` is called again under its key.
- **Two workers on one redelivered message do not undo each other's `sent`.** The slower one's `sending`, `deferred`, `enqueue_failed` or `failed` is written only while the row is not `sent`; finding it sent, the worker stops and queues no retry.
- **A mail can be sent twice.** Two workers on one redelivered message no longer both deliver it: a worker moves the row to `sending` only when it is not sent and no other worker is sending it. A redelivery that finds another worker on it is published again for after the 15-minute takeover window, not dropped. That copy only takes over a row still `sending` (its worker died); a mail the other worker sent, deferred or failed by then is left as it ended. A worker that died after the server accepted the message, before it marked the row `sent`, is the case that still sends it twice — after those 15 minutes. On a transport that cannot hold a message back (the in-memory one, a custom one, `dual` over such a primary) the redelivery is delivered at once, as before. A project's own `MailRepositoryInterface` gets the one-worker rule only by also implementing `MailSendingClaimInterface`. A server that stored the message but never replied to its end is not retried (see SMTP above). Neither is a mail whose attempt row could not be written after it was sent: its status is written first. A failing `QUIT` after the server's `250` is not a failure.
- **A retry from `send()` on a transport that cannot hold messages back goes out at once.** The worker waits `retryDelay` before re-publishing; `send()` does not, so behind such a transport (the in-memory one, a custom one, `dual` over such a primary) a greylisted mail is tried again immediately and uses up one retry.
- **A row stuck in `pending` or `sending` is taken over after 15 minutes.** A crash between storing and publishing, a worker killed mid-send, or a process that died between a commit and the delivery leaves such a row. For 15 minutes after it last changed, `send()` under its key answers `Queued`, because a worker may still be on it. After that the next `send()` under the key takes the row over and delivers it, and only one of two concurrent resends does; a mail held back for a commit that a resend took over meanwhile is not delivered again. Behind a worker, a mail its dead worker left `sending` is also taken over by the queue's own redelivery (after its ack wait, then the 15 minutes), with no resend; otherwise only a resend picks it up. It is sent twice when the worker was killed after the server accepted it (see above), or when a worker still alive after 15 minutes finishes its send. A row stuck in `queued` (the process died while publishing, or the broker lost the message) is not taken over: a long backlog keeps rows `queued` too. Delete it, or send under a new key. A project's own `MailRepositoryInterface` gets the takeover, and the guard on a held-back mail, only by also implementing `MailRecoveryInterface`.
- **Turning certificate checks on is new.** A `MAIL_HOST` that is an IP address or an alias the certificate does not name, or a relay with a self-signed certificate, now fails with `config_error`. Point `MAIL_HOST` at the name on the certificate, or set `MAIL_VERIFY_PEER=false` for a local test server.
- **Text templates compiled before the upgrade stay HTML-escaped** until `bin/semitexa cache:clear`: Twig keeps the escaping it compiled with.
- **An unknown `MAIL_DRIVER` fails the mail.** The row becomes `failed` with `config_error`, in `send()` and in the worker; fix the setting and `send()` again under the key.
- **The worker reads the config at delivery time.** A queued mail is delivered with the resolver's answer for its tenant and `mailerKey` as it is when the worker runs, not when it was sent. The sender address and bodies were fixed when it was stored.
- **A large inline attachment is refused by `send()`.** Inline contents over 256 KB make `send()` return `Failed` with `attachment_missing` before anything is stored. Use a storage path.
- **A mail needs a recipient.** With no `to`, `cc` or `bcc`, `send()` returns `Failed` with `invalid_recipient` and stores nothing.
- **`send()` inside a database transaction delivers after the commit.** A transaction on the mail's own connection (the default one), in the same coroutine: one on another connection, or a coroutine spawned inside it, does not hold the mail back. The row joins the transaction; the queue message is published, or with `EVENTS_ASYNC=0` the mail is sent, only once the outermost transaction commits (`TransactionManager::afterCommit()`). `send()` then answers `queued`. After a rollback nothing is sent, so `runWithRetry()` replaying the unit sends the mail once. Inside a test transaction (`RollsBackDatabase`) nothing commits, so nothing is sent: assert on the stored row. A failure after the commit is in the row and the `mail` log channel, not in what `send()` returned. If the process dies between the commit and the delivery, the row stays `pending` until a resend of its key takes it over (see the stuck-row note above).
- **The tables grow unless you set `MAIL_RETENTION_DAYS`.** Every rendered body and every inline attachment stays in `mail_messages` until the retention sweep deletes it.
