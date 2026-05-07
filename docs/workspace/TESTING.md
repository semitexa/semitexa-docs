# Testing in Semitexa

Semitexa is a **Docker-based** framework. Tests are executed inside the project's test container so that the PHP runtime, Swoole extensions, service dependencies, and file layout all match the real execution environment.

## The only supported command

```bash
bin/semitexa test:run
```

That is the single entry point. It runs **two phases** end-to-end:

1. **PHPUnit** — starts the test container from `docker-compose.yml` (+ active overlays) and `docker-compose.test.yml`, enforces `APP_ENV=dev`, auto-discovers the root `tests/` tree plus `packages/*/tests/` and `src/modules/*/tests/`, and forwards any extra arguments straight to PHPUnit.
2. **E2E (Playwright)** — discovers `packages/*/tests/E2E/**/*.spec.ts` and `src/modules/*/tests/E2E/**/*.spec.ts`, brings up the application service, and executes every spec inside a containerized Playwright runner. Skipped if no E2E specs exist or if positional PHPUnit targets were passed.

If PHPUnit fails, the E2E phase is skipped and the runner exits with PHPUnit's exit code. If E2E fails, the runner exits with the E2E exit code. Either failure surfaces as a non-zero exit from `bin/semitexa test:run`.

## Do not run PHPUnit directly

Running `vendor/bin/phpunit` on the host is **not supported** and is not equivalent to `bin/semitexa test:run`:

- The host typically does not have the same PHP version, Swoole build, or extension set as the container.
- Required services (e.g. MySQL, Redis) are wired only via the test compose overlay.
- Monorepo test-path discovery happens in the wrapper, not in `phpunit.xml.dist`.

If a test passes locally on the host and fails in CI — or vice versa — direct PHPUnit usage is the usual cause.

## Common invocations

Run the whole suite:

```bash
bin/semitexa test:run
```

Filter by test name:

```bash
bin/semitexa test:run --filter MyFeatureTest
```

Target a specific file:

```bash
bin/semitexa test:run tests/Integration/MyFeatureTest.php
```

Target a single package's tests:

```bash
bin/semitexa test:run packages/semitexa-core/tests
```

Pass a PHPUnit group:

```bash
bin/semitexa test:run --group=integration
```

Composer's `composer test` script is a thin alias for the same command; it runs `bin/semitexa test:run` under the hood.

## Test directory layout

Every package and module follows the same three-category layout under `tests/`:

```
<package-or-module>/tests/
├── Unit/          # fast, isolated, no real services
├── Integration/   # boots the kernel or hits a real service (DB, Redis, queue, HTTP)
└── E2E/           # browser-driven specs (Playwright)
```

All three names are PascalCase (`Unit`, `Integration`, `E2E`) to match Semitexa's PHP-style category casing. **Do not** add lowercase `tests/e2e/` directories — they are not discovered.

The categories nest under each module's existing `App\Tests\Modules\<Module>\` namespace as `App\Tests\Modules\<Module>\Unit\…` and `App\Tests\Modules\<Module>\Integration\…`. PSR-4 picks the deeper namespaces up automatically.

### Unit

```php
namespace App\Tests\Modules\MyModule\Unit;

public function test_calculator_adds_numbers(): void
{
    $calc = new Calculator();
    $this->assertEquals(4, $calc->add(2, 2));
}
```

### Integration

```php
namespace App\Tests\Modules\MyModule\Integration;

public function test_homepage_returns_200(): void
{
    $client = $this->createClient();
    $response = $client->request('GET', '/');
    $this->assertEquals(200, $response->getStatusCode());
}
```

PHPUnit discovery walks the entire `tests/` tree per package/module, so all three categories are picked up automatically by `bin/semitexa test:run`. No edits to `phpunit.xml.dist` are needed when adding a new test category.

## E2E tests (Playwright)

E2E tests are first-class in Semitexa. The public concept is **E2E** — Playwright is the current execution engine. The runner is invoked through the same single entry point: `bin/semitexa test:run`.

### Where to put E2E specs

Each module owns its E2E tests inside its own `tests/E2E/` directory:

- `packages/<package>/tests/E2E/**/*.spec.ts` — package-local E2E.
- `src/modules/<module>/tests/E2E/**/*.spec.ts` — local app module E2E.

A directory is discovered if it contains at least one `*.spec.ts`. Adding a new module with E2E tests requires no changes to `playwright.config.ts` or to the test runner.

### What runs them

The Playwright execution engine ships as the `e2e-runner` service in `docker-compose.test.yml`, pinned to `mcr.microsoft.com/playwright:v<X.Y.Z>-jammy` (matching the `@playwright/test` version in `package.json`). Browsers and the test runner ship with the image — **no host install of Node, npm, or browsers is required**.

The runner shares the app container's network namespace, so the application is reachable at `http://localhost:9502` (`SWOOLE_PORT`) from inside the runner. Localhost is the only origin Chromium does not auto-upgrade to HTTPS, which keeps the test traffic on the plain Swoole listener without browser-level interference. Override with the `PLAYWRIGHT_BASE_URL` environment variable for ad-hoc runs against a different environment.

### Writing an E2E spec

```ts
// src/modules/MyModule/tests/E2E/landing.spec.ts
import { expect, test } from '@playwright/test';

test('landing page renders', async ({ page }) => {
    await page.goto('/my-module');
    await expect(page.locator('h1')).toContainText('My Module');
});
```

Each module's `tests/E2E/` is self-contained — there is no cross-module helper coupling. Shared concerns (console-error fixtures, custom `test.extend`) live alongside the specs that use them.

### Running just the E2E phase

`bin/semitexa test:run` always runs PHPUnit first. To target only the E2E phase during development, run the runner directly:

```bash
docker compose -f docker-compose.yml -f docker-compose.test.yml run --rm e2e-runner
```

This is for ad-hoc dev only — CI and the canonical workflow always go through `bin/semitexa test:run`.

## Best practices

- Write the test before the code when the behavior is clear.
- Keep unit tests fast — don't hit the database if you don't need to.
- Aim for high coverage on critical business logic and framework-integration seams.
- When a test needs a real service (DB, Redis, NATS), rely on the test compose overlay rather than spinning up host processes.

## See also

- [PHPSTAN.md](PHPSTAN.md) — static analysis discipline; run via `composer phpstan` / `composer phpstan:strict`.
- `packages/semitexa-testing/` — Semitexa's payload-testing toolkit (attribute-driven HTTP / security / type strategies).
