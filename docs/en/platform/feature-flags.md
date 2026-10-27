---
id: platform/feature-flags
section: platform
slug: feature-flags
title: Feature Flags
summary: Declare a flag in code with #[AsFeatureFlag], read it in handlers and templates, and switch it per site or per user from the CLI — with percentage rollouts.
order: 90
locale: en
status: canonical
verified_against: 2026.10.20.0340
keywords:
  - AsFeatureFlag
  - FeatureFlagsInterface
  - feature:set
  - feature:list
  - rollout
---

# Feature Flags

## Declare

A flag belongs to the module that uses it. Declare it on any class of that module — by convention a `FeatureFlags` class:

```php
use Semitexa\Core\Attribute\AsFeatureFlag;

#[AsFeatureFlag('checkout.new-flow', description: 'The one-page checkout')]
#[AsFeatureFlag('search.semantic', default: true)]
#[AsFeatureFlag('editor.ai-assist', percent: 10)]
final class FeatureFlags {}
```

The declaration is the default and the documentation: `default` (off unless said), a `description` that `feature:list` shows, and an optional `percent` — on for that share of users. A name is lower-case letters, digits, `.`, `_` and `-`. A flag nobody declared is always off.

## Read

```php
use Semitexa\Core\Feature\FeatureFlagsInterface;

#[InjectAsMutable]
protected FeatureFlagsInterface $flags;

if ($this->flags->isEnabled('checkout.new-flow')) { ... }
```

```twig
{% if feature('checkout.new-flow') %} … {% endif %}
```

The answer is for the current request's site and signed-in user. A job or a command asks for someone explicitly: `$flags->forSubject($tenantId, $userId)->isEnabled(...)`. Outside a request, `feature()` in a template answers as the injected service does there: for the site that work runs under, and without a user. A queue worker has no site, so that is the "every site" setting. A mail is the exception: `feature()` in its templates answers for the mail's `tenantId` (every site when it names none), and for no user, also when the mail is sent during a request. To render something else for one site, wrap the render in `CurrentFeatureFlags::forSubjectDuring($tenantId, $userId, fn () => ...)`. A check never throws: when the store of overrides cannot be read, the declared default answers. Each worker logs that at most once a minute (channel `feature`), with the number of checks that fell back since its last warning.

## Switch

```bash
bin/semitexa feature:set checkout.new-flow on                          # every site
bin/semitexa feature:set checkout.new-flow off --tenant=acme           # one site
bin/semitexa feature:set checkout.new-flow on --tenant=acme --user=42  # one user of that site
bin/semitexa feature:set editor.ai-assist 25%                          # a rollout
bin/semitexa feature:set checkout.new-flow clear --tenant=acme         # back to the next scope out
bin/semitexa feature:list --tenant=acme --user=42                      # value, and where it comes from
```

The most specific setting wins: **user**, then **site**, then **every site**, then the declaration. `feature:set` refuses a flag no module declares (it would read as off anyway), and a `--tenant` that is neither `default` (the every-site row) nor one of the sites the tenant fanout knows (with `semitexa/tenancy`, the active tenants of its registry: `TENANTS` and `TENANT_<ID>_*`); `--force` stores it regardless. A `--user` is not checked: the user providers do not know which site a user belongs to. In code, `FeatureFlagWriter` checks the flag only. A user's own overrides are not removed when the user is deleted: clear them with `feature:set <flag> clear --tenant=… --user=… --force`.

## How it behaves

- **Where it is kept:** `semitexa/platform-settings`, module `feature_flags`, one row per flag and scope. "Every site" is the `default` tenant's row; on a single-site install that is also the site row.
- **How fast:** a change reaches every worker within 2 seconds (the settings snapshot's lifetime); a user's flags are read once per request (a `feature()` outside the route pipeline, such as on an error page, reads them on each call). Not a kill switch for something that must stop within the second.
- **Rollouts:** a user is in or out by a hash of the flag and their id, so they get the same answer on every request and every worker; raising the share only adds users. A guest is decided by site. Changing the share moves users at the margin only.
- **Without platform-settings:** core answers the declarations alone.
- **In tests:** `new InMemoryFeatureFlags(['checkout.new-flow' => true])` wherever `FeatureFlagsInterface` is expected.
