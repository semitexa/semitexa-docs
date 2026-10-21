---
id: testing/requests-and-fakes
section: testing
slug: requests-and-fakes
title: Requests and Fakes
summary: Call your routes from a test with CallsRoutes, and replace events, outbound HTTP and mail with fakes that record what your code did.
order: 30
locale: en
status: canonical
verified_against: 2026.10.20.0340
keywords:
  - CallsRoutes
  - TestResponse
  - FakeEventDispatcher
  - FakeHttpClient
  - FakeMailTransport
---

# Requests and Fakes

## Call a route

`CallsRoutes` (semitexa/testing) sends a request through the whole application in the test's own process: routing, authentication, access rules, validation, the handler and the response. No server and no network.

```php
use PHPUnit\Framework\TestCase;
use Semitexa\Testing\Traits\CallsRoutes;

final class OrdersApiTest extends TestCase
{
    use CallsRoutes;

    public function test_an_order_is_created(): void
    {
        $this->postJson('/api/orders', ['sku' => 'A-1'], ['Authorization' => 'Bearer ' . $this->token])
            ->assertStatus(201)
            ->assertJsonPath('data.sku', 'A-1');

        $this->getJson('/api/orders/unknown')->assertStatus(404);
    }
}
```

| Method | Sends |
|---|---|
| `get($uri, $headers)` | a GET as a browser would |
| `getJson($uri, $headers)` | a GET with `Accept: application/json` |
| `post($uri, $fields, $headers)` | a form post |
| `postJson`, `putJson`, `patchJson` (`$uri, $data, $headers`), `deleteJson($uri, $headers)` | JSON in and out |
| `call($method, $uri, $post, $content, $headers)` | anything else |

The query string in `$uri` is parsed as the server parses it. Headers go with one call and win over the defaults (`Accept`, `Content-Type`, `Host: localhost`); a `Cookie` header is read too. `withCookie($name, $value)` sends a cookie with every call after it, so a test can carry a session id it read from `$response->header('Set-Cookie')`. An exception the application lets out reaches the test, where the server would answer 500.

Every call returns a `TestResponse`:

- `assertStatus(201)`, `assertOk()`, `assertHeader('Content-Type', 'application/json')`, `assertJsonPath('data.items.0.id', 7)`, `assertSee('Welcome')`, `assertDontSee('Error')` — each returns the response, so they chain; a failure shows the start of the body.
- `status()`, `body()`, `header($name)`, `json()` and `json('data.id')` read it.

The requests run against the database and services the test environment is configured with, and their writes stay. See [Factories](factories.md) for building the rows a test needs.

## Fake the events

`FakeEventDispatcher` (semitexa/core) records every event and runs no listener:

```php
use Semitexa\Core\Event\FakeEventDispatcher;

$events = new FakeEventDispatcher();
// hand $events to the service under test where it expects EventDispatcherInterface, run it, then:
$events->assertDispatched(OrderPaid::class, fn (OrderPaid $e) => $e->getOrderId() === 42);
$events->assertDispatchedTimes(OrderPaid::class, 1);
$events->assertNotDispatched(OrderRefunded::class);
```

`dispatchedOf(OrderPaid::class)` returns the events themselves; `assertNothingDispatched()` and `reset()` do what they say. `create()` builds an event as the real dispatcher does. A failed assertion throws `\LogicException`, which PHPUnit reports as an error. The fake sees only what is handed to it: a request made through `CallsRoutes` uses the application's own dispatcher.

## Fake outbound HTTP

`FakeHttpClient` answers the calls your code makes through `HttpClientInterface` and records them; see [Outbound HTTP](../runtime/outbound-http.md#in-tests).

## Fake the mail

`FakeMailTransport` (semitexa/mail) keeps what would have been sent:

```php
use Semitexa\Mail\Application\Service\FakeMailTransport;
use Semitexa\Mail\Application\Service\MailTransportRegistry;

$mail = new FakeMailTransport();
MailTransportRegistry::register($mail);   // under the driver name "fake"
putenv('MAIL_DRIVER=fake');                // read on every send

// ... run the code that sends ...

$mail->assertSentCount(1);
self::assertSame('Your order', $mail->getLastMessage()?->subject);
```

`getSentMessages()` lists every message (subject, `to`, `htmlBody`, `textBody`, attachments), `assertNothingSent()` and `reset()` complete it. `MailSendMode::Queued` is the default; when the queue is the in-process one (`EVENTS_ASYNC=0`) a queued message is sent at once and reaches the fake, otherwise only when a worker sends it, so send with `MailSendMode::Sync` where it matters. Put `MAIL_DRIVER` back in `tearDown()`.
