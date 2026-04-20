# Testing in Semitexa

Semitexa is a **Docker-based** framework. Tests are executed inside the project's test container so that the PHP runtime, Swoole extensions, service dependencies, and file layout all match the real execution environment.

## The only supported command

```bash
bin/semitexa test:run
```

That is the single entry point. It:

- starts the test container from `docker-compose.yml` (+ any active overlays) and `docker-compose.test.yml`,
- enforces `APP_ENV=dev` (tests are rejected outside the dev environment),
- auto-discovers test paths from `tests/` and `packages/*/tests/` in monorepo layouts,
- forwards any extra arguments straight to PHPUnit.

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
bin/semitexa test:run tests/Feature/MyFeatureTest.php
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

## Writing tests

Unit tests are fast and isolated:

```php
public function test_calculator_adds_numbers(): void
{
    $calc = new Calculator();
    $this->assertEquals(4, $calc->add(2, 2));
}
```

Integration / feature tests boot the application kernel:

```php
public function test_homepage_returns_200(): void
{
    $client = $this->createClient();
    $response = $client->request('GET', '/');
    $this->assertEquals(200, $response->getStatusCode());
}
```

Place tests in:

- `tests/` — application-level tests.
- `src/modules/*/Tests/` — module-local tests.
- `packages/<package>/tests/` — package-local tests (monorepo).

All three are picked up automatically by `bin/semitexa test:run`.

## Best practices

- Write the test before the code when the behavior is clear.
- Keep unit tests fast — don't hit the database if you don't need to.
- Aim for high coverage on critical business logic and framework-integration seams.
- When a test needs a real service (DB, Redis, NATS), rely on the test compose overlay rather than spinning up host processes.

## See also

- [PHPSTAN.md](PHPSTAN.md) — static analysis discipline; run via `composer phpstan` / `composer phpstan:strict`.
- `packages/semitexa-testing/` — Semitexa's payload-testing toolkit (attribute-driven HTTP / security / type strategies).
