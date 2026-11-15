---
id: testing/e2e-data
section: testing
slug: e2e-data
title: E2E Data
summary: Where an end-to-end spec gets the rows it reads — from the spec itself or from an #[AsE2eFixture] class on the E2E database — and never from rows your development database grew.
order: 40
locale: en
status: canonical
verified_against: 2026.11.13.0659
keywords:
  - E2E
  - AsE2eFixture
  - e2e:fixtures
  - SEMITEXA_E2E_DB
  - fixtures
  - test:run
---

# E2E Data

> E2E fixtures and the runner that applies them are available from Semitexa
> **2026.11.15.0920**; `bin/semitexa update` brings them.

`bin/semitexa test:run` runs the end-to-end phase on an app and a database of
its own, not the ones you work in. Whatever a spec reads has to be in that
database when it runs. This page is about where those rows come from.

## The E2E database

Each run makes the E2E database again (`DB_E2E_DATABASE`, `semitexa_e2e` by
default) and fills it as `SEMITEXA_E2E_DB` says:

| Mode | What the database holds |
|---|---|
| `copy` (default) | A snapshot of your development database, then the schema and data patches. Outbound work (queued jobs, mail, webhooks) is emptied so nothing is sent twice. |
| `clean` | The schema and data patches only. |

In both modes the runner then applies the E2E fixtures (below).

A spec that passes on `copy` and fails on `clean` is reading rows your
development database grew through use. Run the suite on `clean` to find them:

```bash
SEMITEXA_E2E_DB=clean bin/semitexa test:run
```

## Where a spec's rows come from

In this order of preference:

1. **The spec makes them**, through the app's own pages and endpoints, before
   it reads them. A grid spec that needs two pages adds rows with the page's
   "add" action until there are enough; a spec that needs a product creates one
   through the create dialog when the grid is empty. Nothing outside the spec
   has to be kept in step with it.
2. **An E2E fixture makes them**, when no page can: an account to sign in with,
   exchange rates, a catalog of published listings. See below.

Never a row that only exists because someone made it by hand, and never a seeder
run against your development database.

## E2E fixtures

A fixture is a class marked `#[AsE2eFixture]`. The runner applies every fixture
of the installed packages and the project after the schema and data patches,
with `bin/semitexa e2e:fixtures`:

```php
<?php

declare(strict_types=1);

namespace Semitexa\Modules\Fx\Application\Service\E2e;

use Semitexa\Modules\Fx\Domain\Service\RateSeeder;
use Semitexa\Testing\Application\Service\E2e\E2eFixtureContext;
use Semitexa\Testing\Attributes\AsE2eFixture;
use Semitexa\Testing\Contract\E2eFixtureInterface;

#[AsE2eFixture(id: 'fx.rates', priority: 200)]
final class FxRatesFixture implements E2eFixtureInterface
{
    public function apply(E2eFixtureContext $context): string
    {
        $seeder = $context->container->get(RateSeeder::class);
        $count = $seeder->seedFixedRates(); // idempotent: a copied database already has some

        return sprintf('%d exchange rates', $count);
    }
}
```

- `apply()` returns one line for the runner's log.
- The context gives the E2E database (`$context->db`), the container, and the
  environment the runner set (`$context->env('NAME')`).
- A fixture runs on a fresh database and on a copied one, so it creates or
  resets its rows and never assumes either. Calling the module's own seeder
  keeps the fixture and the seeder from drifting apart.
- Fixtures run in ascending `priority`, then by `id`. A fixture that needs
  another's rows declares a higher number; the built-in sign-in owner is `100`.
- The class needs a constructor with no arguments. A fixture that cannot be
  loaded or made stops the run before any fixture writes.

### Where a fixture can run

`e2e:fixtures` refuses, before any fixture runs, unless all three hold:

1. `APP_ENV` is one of `dev`, `test`, `testing`, `e2e`, `local`;
2. the connection is on the E2E database (`DB_E2E_DATABASE`);
3. that database holds a `_semitexa_e2e` row with this run's token, which the
   runner writes into the database it has just made, and passes to the command.

A production database never holds that row, so a fixture never writes there.
Run by hand on your development database, the command says why it refuses.

### The sign-in owner

`semitexa/testing` ships one fixture: an owner the specs sign in as,
`owner@semitexa.test`, with a password the runner makes for this run. The specs
get both as `CMS_E2E_EMAIL` and `CMS_E2E_PASSWORD`:

```ts
const EMAIL = process.env.CMS_E2E_EMAIL ?? 'owner@semitexa.test';
const PASSWORD = process.env.CMS_E2E_PASSWORD ?? 'your-local-password';
```

No fixed password is written anywhere. The fallback is only for a hand run
against your own database.

A project whose `semitexa/testing` has no `e2e:fixtures` yet is told so, and the
run goes on without fixtures; update it with `bin/semitexa update`.

## Services from docker-compose.test.yml

If `docker-compose.test.yml` adds a service to the app's `depends_on` — a
scripted model the app calls, a mail catcher — the runner starts it before the
E2E app and waits for it as its condition says (`service_healthy`,
`service_completed_successfully`). Services the runtime files declare, such as
MySQL, are yours and are left alone.

Such a service runs with your development environment, not the E2E app's. A
one-shot that seeds data through it writes into your development database, not
the E2E one. On `copy` the rows reach the E2E database only because the snapshot
carries them, as of the last time the one-shot ran (rates seeded two days ago
are two days old). On `clean` they are missing. Move that seeding into an
`#[AsE2eFixture]`, which runs on the E2E database on every run.

See also [Test Data Factories](factories.md) for building rows inside a PHPUnit
test.
