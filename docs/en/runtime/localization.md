---
id: runtime/localization
section: runtime
slug: localization
title: Localization
summary: Translation catalogs, trans() and plurals, how each request's locale is resolved (URL prefix, cookie, Accept-Language, tenant pack), locale-aware URLs and redirects, and how the locale travels through coroutines, async work and queued jobs.
order: 95
locale: en
status: canonical
verified_against: 2026.10.20.0340
keywords:
  - semitexa/locale
  - trans()
  - trans_choice()
  - locale_url()
  - locale_switch_url()
  - TranslationService
  - LocaleContextStore
  - LocaleContextInterface
  - LOCALE_URL_PREFIX
  - LOCALE_SUPPORTED
  - Accept-Language
  - plural forms
  - translation overrides
---

# Localization

`semitexa/locale` does three things on every HTTP request:

1. **Resolves the locale** from the URL prefix, the `locale` cookie or `Accept-Language`, under the current tenant's language pack.
2. **Stores it per coroutine** (`LocaleContextStore`), so concurrent requests in one worker never see each other's language.
3. **Translates** keys from JSON catalogs, with named placeholders, plural forms, one fallback locale and optional per-tenant database overrides.

`semitexa/ssr` adds the Twig functions (`trans`, `trans_choice`, `locale`, `locale_url`, `locale_switch_url`) and makes `url()` and handler redirects locale-aware. The package ships no console commands, and no date or number formatting: format those yourself with the locale code from `locale()`.

## Translation files

One JSON file per locale, named by the locale code, in the module's `View/locales` directory:

```text
src/modules/Shop/src/Application/View/locales/en.json
src/modules/Shop/src/Application/View/locales/uk.json
```

The older layout `src/modules/<Module>/Application/View/locales/` is still read. Installed packages are read from `locales/` next to each module's template directory.

```json
{
    "shop.title": "Shop",
    "shop.greeting": "Hello, {{name}}!",
    "shop.items": {
        "one": "{{count}} item",
        "other": "{{count}} items"
    }
}
```

- A value is either a string or a **plural map** whose keys are only `zero`, `one`, `two`, `few`, `many`, `other`.
- Anything else is dropped without a warning: numbers, booleans, and nested objects used for grouping (`{"nav": {"home": "Home"}}` is not a plural map, so the whole `nav` entry disappears). Use dotted keys instead.
- A file that is not valid JSON is skipped whole, also silently.

### Keys and namespaces

Every key is stored twice: under the module prefix (`Shop.shop.title`, where `Shop` is the module directory name, or the short module alias such as `os` for a package), and bare (`shop.title`). When two modules ship the same bare key, the first one loaded keeps it. Prefix your keys with the module's own name inside the file, as the shipped modules do (`hello.*`, `shell.*`), and look them up bare.

### When files are read

The catalog is built once per worker, on first use, and kept for the worker's lifetime. Editing a JSON file has no effect until the workers restart (`bin/semitexa server:restart app`).

## Translating in Twig

```twig
<html lang="{{ locale()|e('html_attr') }}">
<h1>{{ trans('shop.title') }}</h1>
<p>{{ trans('shop.greeting', {name: user.name}) }}</p>
<p>{{ trans_choice('shop.items', cart.count) }}</p>
```

| Function | Returns |
|---|---|
| `trans(key, params = {})` | the message in the current locale |
| `trans_choice(key, count, params = {})` | the plural form for `count` |
| `locale()` | the current locale code |
| `locale_url(path, locale = null)` | `path` with a locale prefix (see [URLs](#locale-aware-urls-and-redirects)) |
| `locale_switch_url(locale)` | the current page in another locale |

Output is escaped like any other Twig function result. A translation that contains HTML is printed as text unless you add `|raw`, and only do that for catalog strings you control.

## Translating in PHP

`TranslationService` is the implementation behind the Twig functions. It is not registered in the container, so it cannot be injected; the working entry point is the `Translator` facade from `semitexa/ssr`, which builds the catalog once per worker and translates in the request's locale:

```php
use Semitexa\Ssr\Application\Service\I18n\Translator;

$title   = Translator::trans('shop.title');
$hello   = Translator::trans('shop.greeting', ['name' => $user->getName()]);
$items   = Translator::transChoice('shop.items', $count);
$locale  = Translator::getLocale();

// Explicit locale, existence check, key enumeration:
$service = Translator::getService();
$german  = $service->trans('shop.title', [], 'de');
$exists  = $service->hasTranslation('shop.title');
$keys    = Translator::getCatalog()->keys('en', 'shop.');
```

`Translator` is marked deprecated in favour of injecting `TranslationService`, but no injectable service exists yet, so it remains the way in.

### Placeholders

Placeholders are `{{name}}`, replaced by plain string substitution from the `params` array. Single braces (`{name}`) and `:name` are not placeholders in `trans()`.

### Plurals

`transChoice()` / `trans_choice()` picks a category from the count and then substitutes the count three ways: `{{count}}`, `:count`, and `count` added to `params`.

| Languages | Categories used |
|---|---|
| `en`, `de`, `nl`, `sv`, `da`, `no`, `nb`, `nn` | `one` (exactly 1), `other` |
| `uk`, `ru`, `be` | `one` (1, 21, 31…, not 11), `few` (2–4, 22–24…, not 12–14), `many` (everything else) |
| `pl` | `one` (exactly 1), `few` (2–4, 22–24…, not 12–14), `many` |
| any other language | treated like `en`: `one` / `other` |

The region is ignored (`uk-UA` uses the `uk` rules). If the chosen category is missing from the map, `other` is used, then `one`. `zero` and `two` are accepted in files but no rule ever selects them. A plain string `"one form|other form"` is still accepted as a two-form plural. `trans()` on a plural key returns its `other` form.

### Fallback order

For one key, the first hit wins:

1. tenant override for the current locale
2. catalog, current locale
3. tenant override for the fallback locale (`LOCALE_FALLBACK`, or the tenant pack's `fallback`)
4. catalog, fallback locale
5. the key itself

There is no region fallback: with `LOCALE_SUPPORTED=pt-BR`, only `pt-BR.json` is read for that locale, never `pt.json`.

### Per-tenant overrides

A tenant can replace any message without touching the shipped catalog. Overrides live in the tenant-scoped table `locale_translation_override` (create it with `bin/semitexa orm:sync`) and are written through `TranslationOverrideStore`, an `#[AsService]`:

```php
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Locale\Application\Service\I18n\TranslationOverrideStore;

#[InjectAsReadonly]
protected TranslationOverrideStore $overrides;

// For the current tenant only:
$this->overrides->set('en', 'shop.title', 'Store');
$this->overrides->remove('en', 'shop.title');
```

Each request loads the current tenant's overrides for a locale with one query, the first time it translates in that locale. If the table is missing or the query fails, translation falls back to the catalog and the failure is logged once per tenant and locale per worker.

## How the request locale is resolved

The locale phase runs after tenancy and session, before routing. It:

1. Takes the tenant's language pack (`LOCALE_*` settings, overlaid with the tenant's `locale` platform settings `default`, `fallback`, `supported` when `semitexa/ssr` and platform settings are present).
2. Sets the locale to the pack's default and the fallback to the pack's fallback.
3. Tries the resolvers in priority order. The first one that returns a supported locale wins.

| Resolver | Reads | Matches |
|---|---|---|
| `path` | first path segment, `/uk/catalog` | exact, case-sensitive code from the supported list |
| `cookie` | cookie `locale` | exact code from the supported list |
| `header` | `Accept-Language`, highest `q` first | exact tag (normalized to `xx-YY`), then its primary subtag (`en-US` → `en`) |

The priority comes from `LOCALE_STRATEGY` and `LOCALE_COOKIE_ENABLED`:

| `LOCALE_STRATEGY` | without cookie | with `LOCALE_COOKIE_ENABLED=true` |
|---|---|---|
| `path` | path | cookie, path |
| `header` | header | cookie, header |
| `both` or unset | path, header | cookie, path, header |

With `LOCALE_URL_PREFIX=true`, `path` is moved to the front and becomes **authoritative**: a prefixed URL uses its prefix, and an unprefixed URL is the default locale. The cookie and header resolvers are never reached.

The framework does not read the session or a user profile for the locale, and it never writes the `locale` cookie. Storing a person's choice is the application's job (see the switcher below). A `LocaleResolved` event (`locale`, `resolvedBy`) is dispatched once a locale is chosen, including the default chosen for an unprefixed URL when prefixing is on; it is not dispatched when no resolver matched.

With tenancy, the tenant's locale layer is read from the same URL prefix (`TENANCY_LOCALE_PREFIXES`), and `TenantResolvedLocaleListener` copies it into the context when the tenant is resolved. The locale phase then resolves the prefix itself, so the layer only matters with `LOCALE_ENABLED=false`. To give a tenant its own default language, set its pack (`default`, `supported`).

## Locale-aware URLs and redirects

With `LOCALE_URL_PREFIX=true`:

- **Routing** sees the path without the prefix: `/uk/catalog` matches the `/catalog` route.
- **The default locale is never prefixed.** `GET`/`HEAD` on `/en/catalog` (when `en` is the default) is answered with `301` to `/catalog`, query string kept. Set `LOCALE_REDIRECT_DEFAULT=false` to serve it instead.
- **A stored preference redirects.** A `GET`/`HEAD` page request (its `Accept` contains `text/html`) on an unprefixed path, carrying a `locale` cookie with a supported, non-default code, is answered with `302` to the prefixed URL and `Vary: Cookie, Accept`. Requests without the cookie (crawlers) are not redirected. This check reads the cookie even when `LOCALE_COOKIE_ENABLED` is not set.
- **`url('route.name')`** and `RouteUrlBuilder::to()` prefix the current locale, unless it is the default.
- **`locale_url(path, locale)`** prefixes `path` with `locale` (the current one by default), unless it is the default.
- **`locale_switch_url(locale)`** strips any prefix from the current path, drops the query string, and prefixes the target. As a plain link it is bounced back by the preference redirect when it targets the default language and the visitor's cookie says otherwise; use the switcher below. `locale_switch_url(<default>)` is also the unprefixed current path the switcher needs.
- **Handler redirects**: `$resource->setRedirect('/account')` becomes `/uk/account` while serving `uk`. Absolute URLs and paths that already start with a supported locale are left alone.

With prefixing off, all of these return the path unchanged and nothing is redirected. The sitemap emits per-locale alternates only when prefixing is on.

### A language switcher

Because of the preference redirect, a plain link to the default-language page bounces a visitor whose cookie says otherwise straight back. Change the cookie in the same step as the navigation, and switch the current locale before redirecting: a bare `setRedirect()` path is prefixed with the locale being served, so after `setLocale()` it lands in the new language. This works for a `GET` link and a `POST` form alike; register the route for both if you use both, and, as with any `POST`, send the `_csrf` field for a signed-in visitor:

```php
use Semitexa\Core\Attribute\InjectAsMutable;
use Semitexa\Core\Cookie\CookieJarInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Locale\Context\LocaleContextStore;

// Handler for /language, fields: locale, path (e.g. "/catalog")
#[InjectAsMutable]
protected CookieJarInterface $cookies;

public function handle(SwitchLanguagePayload $payload, ResourceResponse $resource): ResourceResponse
{
    $locale = $payload->getLocale();
    if (!in_array($locale, LocaleContextStore::getSupportedLocales(), true)) {
        $locale = LocaleContextStore::getDefaultLocale();
    }

    $this->cookies->set('locale', $locale, ['path' => '/', 'maxAge' => 31536000, 'sameSite' => 'lax']);
    LocaleContextStore::setLocale($locale);

    // A path that still carries a locale is left alone by the redirect, so the
    // page would stay in the old language: drop the prefix here.
    $segments = explode('/', ltrim($payload->getPath(), '/'), 2);
    if (in_array($segments[0], LocaleContextStore::getSupportedLocales(), true)) {
        $segments = [$segments[1] ?? ''];
    }

    return $resource->setRedirect('/' . implode('/', $segments));
}
```

A browser `GET` to `/language` from a visitor whose cookie names a supported language other than the default is first redirected to `/uk/language?…` (query kept); the handler still runs, and its redirect uses the new locale, not the cookie's. A `GET` switch is a state change any link can trigger, which here only changes a visitor's language.

## The locale under Swoole

`LocaleContextStore` keeps the locale, fallback, default, supported set and prefix flag in `Swoole\Coroutine::getContext()`: per coroutine, gone when the coroutine ends. Read it through the container or statically:

```php
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Locale\LocaleContextInterface;
use Semitexa\Locale\Context\LocaleContextStore;

#[InjectAsReadonly]
protected LocaleContextInterface $localeContext;   // LocaleManager: a view over the store

$code = $this->localeContext->getLocale();
$code = LocaleContextStore::getLocale();           // same value
```

Calling `setLocale()` changes the locale for the rest of the current request only.

- **Async handlers** (run in-process after the response) receive the request's locale: the store registers its keys with `CarriedContext`.
- **A coroutine you start yourself** begins with no context. Capture with `CarriedContext::capture()` before and run inside `CarriedContext::runWith()`, or set the locale again.
- **Queued jobs and CLI commands** run outside a request coroutine and use a static fallback. Nothing resolves a locale there and nothing carries the sender's: it is `LOCALE_DEFAULT`, fallback `LOCALE_FALLBACK`. Put the locale in the job payload and set it at the start of the job. The queue worker resets it to `LOCALE_DEFAULT` after every job, so one job's locale never leaks into the next.
- **Mail** renders in `MailEnvelope::$locale` when set (matched to the supported list, `uk_UA` → `uk`), and restores the sender's locale afterwards. Without it, the sender's locale is used, which in a queued job is `LOCALE_DEFAULT`.

## Configuration

| Key | Default | Used for |
|---|---|---|
| `LOCALE_ENABLED` | `true` | `false` skips locale resolution entirely: the locale stays `LOCALE_DEFAULT`, or, with tenancy, the URL prefix's locale layer |
| `LOCALE_DEFAULT` | `en` | locale when nothing matches; never prefixed |
| `LOCALE_FALLBACK` | `en` | second catalog consulted for a missing key |
| `LOCALE_SUPPORTED` | `en,uk,de,pl,ru` | comma-separated codes every resolver validates against |
| `LOCALE_STRATEGY` | unset (= path, header) | `path`, `header` or `both` |
| `LOCALE_COOKIE_ENABLED` | `false` | put the `locale` cookie resolver first |
| `LOCALE_URL_PREFIX` | `false` | locale prefix in URLs; makes the path authoritative |
| `LOCALE_REDIRECT_DEFAULT` | `true` | 301 from `/{default}/…` to the bare path |

Values are read once per worker; restart after changing them. A tenant's pack (platform settings, module `locale`) can replace `default`, `fallback` and `supported`; a tenant default outside its own supported list is replaced by the global default, or the first supported locale.

## Watch out for

- **App modules cannot override a package's bare key.** Package catalogs load first and the first bare key wins. Use your own key prefix, or a tenant override.
- **Nested JSON objects vanish.** Only plural maps are kept; a grouping object, a number or a boolean is dropped without a message.
- **`{count}` is not a placeholder.** Only `{{count}}` and `:count` are replaced.
- **Catalog edits need a worker restart.** So do `LOCALE_*` changes.
- **With `LOCALE_URL_PREFIX=true`, `Accept-Language` and the cookie resolver are ignored.** A first-time visitor gets the default language on an unprefixed URL; only a `locale` cookie your application wrote triggers the preference redirect.
- **The preference redirect beats a plain link.** A visitor with `locale=uk` who follows a link to `/catalog` is sent to `/uk/catalog`. Switch languages by updating the cookie (above), not by linking to the bare path.
- **A redirect follows the locale being served.** `setRedirect('/catalog')` on a Ukrainian page goes to `/uk/catalog`. A language switcher must call `LocaleContextStore::setLocale()` with the new locale first (see above).
- **`locale_url()` replaces a prefix that is already there.** `locale_url('/uk/a', 'de')` is `/de/a`. A first segment that is not a supported locale (`/ukraine/a`) is kept.
- **Supported codes must be spelled as the header resolver normalizes them.** It compares `pt-BR`, so `LOCALE_SUPPORTED=pt-br` never matches a full tag. Only the primary subtag fallback can, and only when `pt` is in the list too.
- **`Accept-Language: uk;q=0` never selects `uk`.** A zero quality excludes the language; a weight that is not a number (`q=x`) counts as `1`.
- **Queued jobs translate in `LOCALE_DEFAULT`, not in the sender's locale.** Carry the locale in the payload.
- **Unknown languages get English plural rules.** French, Czech, Arabic and others fall back to `one` / `other`.
