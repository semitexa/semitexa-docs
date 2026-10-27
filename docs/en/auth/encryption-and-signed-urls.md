---
id: auth/encryption-and-signed-urls
section: auth
slug: encryption-and-signed-urls
title: Encryption and Signed URLs
summary: Encrypt a value that must come back unread and untouched, and hand out links that prove they came from your application — both keyed from APP_SECRET, with rotation.
order: 80
locale: en
status: canonical
verified_against: 2026.10.20.0340
keywords:
  - EncrypterInterface
  - UrlSigner
  - RequiresSignedUrl
  - APP_SECRET
  - APP_SECRET_PREVIOUS
---

# Encryption and Signed URLs

Both use the application secret, `APP_SECRET`. Set it to a long random value in every environment except dev and test (`openssl rand -hex 32`). Without it, dev and test fall back to a value derived from `APP_NAME`, `APP_HOST` and `APP_PORT`, and any other environment refuses to sign or encrypt anything. Each use derives its own key from the secret, so an encrypted value is never accepted as a signature or the other way round.

## Encrypt a value

Inject `EncrypterInterface`:

```php
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Security\DecryptionException;
use Semitexa\Core\Security\EncrypterInterface;

#[InjectAsReadonly]
protected EncrypterInterface $encrypter;

$token = $this->encrypter->encrypt(json_encode(['order' => 1042]));   // se1.Xk3…

try {
    $data = json_decode($this->encrypter->decrypt($token), true);
} catch (DecryptionException) {
    // changed, made with another secret, or not a token at all
}
```

- A token is URL-safe text starting `se1.`, so it fits a cookie, a hidden field or a query parameter.
- Encrypting the same text twice gives two different tokens.
- Changing one character makes `decrypt()` throw: a token is authenticated as well as encrypted.
- It encrypts strings. Encode anything else first, as above.
- A token is tied to what it was made for only when you say so: pass a context to both sides, `encrypt($value, 'link:invite')` and `decrypt($token, 'link:invite')`, and a token made under one context throws under any other (no context included). Without one, any code that decrypts without a context reads it.

It uses libsodium's secretbox (XSalsa20-Poly1305), which needs the `sodium` extension; the Semitexa image has it.

## Sign a URL

Inject `UrlSigner`:

```php
use Semitexa\Core\Security\UrlSigner;

#[InjectAsReadonly]
protected UrlSigner $signer;

$link = $this->signer->sign('/unsubscribe?list=news&user=42');
// /unsubscribe?list=news&user=42&signature=…

$download = $this->signer->sign('https://example.org/files?id=7', ttlSeconds: 86400);
// …&expires=1792000000&signature=…
```

The signature covers the path and every query parameter, `expires` included, so changing, adding or removing any of them breaks it. The host is not covered: the link verifies on any domain or proxy the application answers on. The order of parameters and their percent-encoding do not matter, and the query you passed is kept as written.

## Accept only signed URLs

Put `#[RequiresSignedUrl]` on the payload:

```php
use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Attribute\RequiresSignedUrl;

#[AsPublicPayload(path: '/unsubscribe', methods: ['GET'])]
#[RequiresSignedUrl]
final class UnsubscribePayload
{
    // ...
}
```

An unsigned, changed or expired URL gets **403**, with "This link is not valid." or "This link has expired." So does a signed URL sent with a body (form fields, JSON or files): the signature covers the path and the query only, and a body field would win over the query value of the same name. Everything a signed route needs travels in its query. The signature does not cover the method either: a signed route answers `GET` and `HEAD` only, and a link sent with another method gets the same 403. A route whose signed link must accept another method says so: `#[RequiresSignedUrl(methods: ['GET', 'POST'])]`. Nor does it cover the host, so on a multi-site install a link made for one site verifies on every other with the same path; to keep it to one site, sign it with the site: `$signer->sign('/unsubscribe?list=news', tenantId: $tenantId)` adds a signed `signed_tenant` parameter, and the link gets the 403 on any other site. The check runs after authorization and before rate limits, so a forged link costs nobody's quota. It does not replace access rules: a signed link to a route that needs a signed-in user still needs one. The path checked is the one the visitor asked for, locale prefix included, so sign the URL exactly as you hand it out. An encoded slash (`%2F`) stays distinct from a real one.

The check runs when the route is requested. Other ways to a payload, such as GraphQL, do not check it, so do not expose a signed payload through them. A live region on a signed page is checked when the page is requested and not again while its stream stays open, even after `expires` passes.

To check a URL in your own code: `$this->signer->checkRequest($request)` or `$this->signer->check($path, $queryString)` returns `SignatureCheck::Valid`, `Expired` or `Invalid`.

## Rotating the secret

1. Move the current value to `APP_SECRET_PREVIOUS` and set a new `APP_SECRET`.
2. Restart. New tokens and links use the new secret; old ones still decrypt and verify.
3. Once nothing signed or encrypted with the old secret needs to work (the longest link TTL, the longest cookie life), remove it from `APP_SECRET_PREVIOUS`.

`APP_SECRET_PREVIOUS` takes several values separated by commas. The signed event contexts of Platform UI components use `APP_SECRET` too, but not `APP_SECRET_PREVIOUS`: after a rotation, a page rendered before it needs a reload before its components answer again.
