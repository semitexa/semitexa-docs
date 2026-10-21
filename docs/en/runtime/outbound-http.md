---
id: runtime/outbound-http
section: runtime
slug: outbound-http
title: Outbound HTTP
summary: Call other services through HttpClientInterface — timeouts, opt-in retries with Retry-After, coroutine-friendly under Swoole — and assert what was sent with FakeHttpClient.
order: 50
locale: en
status: canonical
verified_against: 2026.10.20.0340
keywords:
  - HttpClientInterface
  - CurlHttpClient
  - FakeHttpClient
  - RetryPolicy
  - HttpTransportException
---

# Outbound HTTP

Inject `HttpClientInterface` and call:

```php
use Semitexa\Core\Http\Client\HttpCallOptions;
use Semitexa\Core\Http\Client\HttpClientInterface;
use Semitexa\Core\Http\Client\RetryPolicy;

#[InjectAsReadonly]
protected HttpClientInterface $http;

$response = $this->http->get('https://api.example.com/rates', ['Accept' => 'application/json'],
    new HttpCallOptions(timeout: 10, retry: RetryPolicy::idempotent(3)));

if (!$response->isSuccessful()) {
    // a 4xx or 5xx is a response: decide here
}
$rates = $response->json();
```

`send(HttpRequest)` takes any method; `get()`, `postJson()` and `postForm()` build the common ones. Under the Swoole server the call yields its worker to other requests while it waits (curl is hooked); in a console command it blocks, as a command expects.

## What can go wrong

- **A status:** every status comes back as an `HttpResponse`, 404 and 503 included. Nothing is thrown for it.
- **No response:** `HttpTransportException`, with a `kind` — `connect`, `dns`, `timeout`, `tls`, `too_large`, `protocol` — and the number of `attempts`. Its message names the method and `scheme://host/path` only: no query string, header or body, which is where keys and tokens travel.

## Options

`HttpCallOptions` holds what a call may do. The defaults are meant for a call nobody tuned:

| Option | Default | |
|---|---|---|
| `timeout` | 30 s | the whole attempt |
| `connectTimeout` | 5 s | |
| `maxResponseBytes` | 16 MiB | counted as the body arrives; a body the server compresses anyway is inflated no further than this, so a gzip bomb is `too_large`, not a full memory |
| `truncateOversized` | off | keep the first `maxResponseBytes` of a larger body and succeed — for a body you only log |
| `decodeContent` | on | off returns an encoded body as sent, for a store that keeps an object's own `Content-Encoding` |
| `followRedirects` | off | when on, at most 5 hops; a hop to another origin keeps only `Accept`, `Accept-Language` and `User-Agent` — never `Authorization`, cookies or API keys. Refused together with `pinnedResolve` |
| `noProxy` | off | ignore `HTTP(S)_PROXY` from the environment |
| `pinnedResolve` | none | `host:port:ip`: connect to the address you already checked |
| `httpOnly` | on | only `http://` and `https://` |
| `retry` | `RetryPolicy::none()` | see below |
| `onChunk` | none | a successful body goes to the callback (an error body stays on the response, and a stream that started is never retried) |

## Retries

None unless asked: resending a request is a decision about the other side, and a resent POST can charge a card twice.

- `RetryPolicy::idempotent(3)` — up to 3 attempts for GET, HEAD, PUT, DELETE and OPTIONS. The wait grows exponentially with full jitter (a random wait up to 200 ms·2ⁿ, capped at 5 s), so many clients do not retry in step.
- `RetryPolicy::linear(3, 500)` — 0.5 s, 1 s, 1.5 s.
- `->allowingNonIdempotent()` — POST and PATCH too, for an API known to tolerate a repeat.

A retry follows a transport failure worth another try (not `tls`, `too_large` or `protocol`) and the statuses 429, 500, 502, 503, 504 and 529 (overloaded). A `Retry-After` header, in seconds or as a date, sets the wait, up to the cap. `$response->attempts` says how many it took.

## In tests

`FakeHttpClient` answers in the process and records every request:

```php
use Semitexa\Core\Http\Client\FakeHttpClient;
use Semitexa\Core\Http\Client\HttpRequest;
use Semitexa\Core\Http\Client\HttpResponse;

$http = (new FakeHttpClient())
    ->when('POST', '#/v1/messages$#', HttpResponse::make(200, '{"id":"m1"}'))
    ->queue(HttpResponse::make(503), HttpResponse::make(200));

// inject $http where the service expects HttpClientInterface, run the code, then:
$http->assertSentCount(3);
$http->assertSent(fn (HttpRequest $r) => $r->header('x-api-key') === 'test-key');
```

`when()` routes answer first, then `queue()` in order. A request nothing answers throws, so a test never passes on a response it did not set up. Queue an `HttpTransportException` to test a timeout path. The fake does not retry: it shows what your code sent, and `recordedOptions()` shows the `RetryPolicy` it asked for.

## Under Swoole

Curl is hooked by the server (`SWOOLE_HOOK_ALL`). The hook speaks HTTP/1.1 only and refuses curl options it does not implement, so the client uses only options the hook accepts; `CurlHttpClientCoroutineTest` runs it against a Swoole server to keep it that way. Calling `curl_*` yourself works too, with traps the client already avoids: the hook offers gzip unless told otherwise, a `CURLOPT_WRITEFUNCTION` then receives the body still compressed, and it is called before the header callback has seen the status. So under the server `onChunk` receives the body in one piece once the call succeeds; in the CLI it receives it as it arrives. A header value with a line break is refused when the request is built.

## In a trace

With semitexa/dev installed, every call the container's client makes is a `http.client` span in a dev trace (`?__trace=1`, then `/__trace` or `ai:observe show --id=<process>`): the method and the URL cut to its host and first path segment (`https://api.anthropic.com/v1/…`) — a webhook or bot URL carries its secret further down the path, and a trace is written to disk and rendered — then the status, the attempts and the bytes received (`streamed` for an `onChunk` call), or the transport failure's kind. A client built with `new` is not traced. A call made in a coroutine that outlives its request shows as never closed: the trace ended first.
