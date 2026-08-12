---
id: di/configuration
section: di
slug: configuration
title: Configuration Injection
summary: "#[Config] reads a scalar from the environment into a typed property, with a default in code — the only supported way a container-managed class reads env."
order: 60
locale: en
status: canonical
keywords:
  - Config
  - env
  - default
  - getenv
---
# Configuration Injection

`#[Config]` puts an environment value into a typed property, with the fallback declared next to it:

```php
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\Config;

#[AsService]
final class WebhookConfig
{
    #[Config(env: 'WEBHOOK_TIMEOUT_SECONDS', default: 30)]
    protected int $defaultTimeoutSeconds;

    #[Config(env: 'WEBHOOK_MAX_ATTEMPTS', default: 5)]
    protected int $defaultMaxAttempts;

    #[Config(env: 'WEBHOOK_BACKOFF_MULTIPLIER', default: 2.0)]
    protected float $defaultBackoffMultiplier;
}
```

That is `Semitexa\Webhooks\Configuration\WebhookConfig`, trimmed. The attribute takes exactly two arguments — `env` and `default` — and targets properties only.

## Why not `getenv()`

Because the value is read **once, at boot**, into a typed property. Three things follow that a scattered `getenv()` call cannot give you:

- **The default lives in code, beside the property.** There is one place to read to know what happens when the variable is absent.
- **The type is the property's type.** A `float` property gets a float, not the string `"2.0"`.
- **The key is discoverable.** `docs:truth-index` enumerates every `#[Config]` key in the framework, which is how `docs:lint` can tell a real env variable from an invented one in a documentation page.

A container-managed class reading the environment any other way is bypassing all three.

## Grouping config in one class

The pattern above — one `#[AsService]` class holding a package's settings — is the idiom. Injecting `WebhookConfig` gives a caller typed accessors instead of a spray of `#[Config]` properties on every consumer, and it keeps the package's env surface enumerable in a single file.

## What it does not do

`#[Config]` is for **scalars**. It has no array, JSON or secret-store handling, and no per-tenant awareness: it reads the process environment the worker booted with. Per-tenant values come from tenant configuration instead — see [per-tenant configuration](../platform/tenancy-config.md).

Because the read happens at boot, changing a variable in `.env` requires restarting the worker. That is not a limitation of the attribute so much as of long-running processes generally, but it does surprise people arriving from request-per-process runtimes.
