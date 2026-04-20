# One Way Dependency Injection — constructor injection vs constructors

> Canonical reference for the Semitexa DI policy as it applies to constructors.
> Every other rule, error message, lint output, or doc must agree with this file.

## The rule in one sentence

On **container-managed classes**, dependencies are injected through **protected
properties** annotated with `#[InjectAsReadonly]` / `#[InjectAsMutable]` /
`#[InjectAsFactory]` / `#[Config]`. The constructor is **never** the DI channel.
The constructor itself is **not banned**.

## What this means in practice

- *Constructor injection* is forbidden on container-managed classes.
- *Constructors* are still available, on every class, for what constructors
  are actually for: local initialization, normalization of already-available
  values, setup of internal state, and invariant enforcement on
  self-contained objects.

Nothing else was ever in scope. If a rule, message, or comment suggests
otherwise, treat that as a defect and fix it.

## Which classes are “container-managed”?

A class is container-managed when it carries any of:

- `#[AsService]`
- `#[AsPayloadHandler]`
- `#[AsEventListener]`
- `#[AsPipelineListener]`
- `#[SatisfiesServiceContract]`
- `#[SatisfiesRepositoryContract]`
- `#[AsRepository]` (from `semitexa/orm`)

On these classes the container instantiates via
`ReflectionClass::newInstanceWithoutConstructor()`. That is a deliberate
implementation choice: it prevents the constructor from becoming a covert
injection channel and guarantees that `#[InjectAs*]` / `#[Config]` are the
only visible value inlets.

## Allowed constructor usage

### Allowed on container-managed classes

A parameterless `__construct` is allowed. The container will not call it, so
it is effectively inert, but declaring it for documentation or to run
side-effect-free local setup on manually constructed instances is fine.

```php
#[AsService]
final class UserExporter
{
    #[InjectAsReadonly]
    protected LoggerInterface $logger;

    #[InjectAsReadonly]
    protected UserRepositoryInterface $users;

    public function __construct() // OK — parameterless, not a DI channel
    {
        // local initialization, if any, goes here
    }
}
```

### Allowed on everything else

Non-container-managed types — DTOs, payloads, resources, events, value
objects, exceptions, enums with constructors, context objects — are outside
this rule. Use constructors as you normally would.

```php
final readonly class Money
{
    public function __construct(
        public int $amountMinor,
        public string $currency,
    ) {
        if ($amountMinor < 0) {
            throw new \InvalidArgumentException('negative amount');
        }
        if (strlen($currency) !== 3) {
            throw new \InvalidArgumentException('currency must be ISO 4217');
        }
    }
}
```

```php
final class UserRegisteredEvent
{
    public function __construct(
        public readonly int $userId,
        public readonly string $email,
        public readonly \DateTimeImmutable $registeredAt,
    ) {}
}
```

Payloads, resources, and DTOs follow the same pattern — they are data
shapes, not container-managed services.

## Forbidden pattern

Constructor-based injection on a container-managed class:

```php
#[AsService]
final class UserExporter
{
    // ✗ Rejected by InjectionViaConstructorRule (PHPStan)
    //   and by the runtime container (InjectionException).
    public function __construct(
        private LoggerInterface $logger,
        private UserRepositoryInterface $users,
    ) {}
}
```

The fix is never “delete the constructor”. The fix is to move the
dependencies onto protected properties with the injection attribute that
matches the scope:

```php
#[AsService]
final class UserExporter
{
    #[InjectAsReadonly]
    protected LoggerInterface $logger;

    #[InjectAsReadonly]
    protected UserRepositoryInterface $users;
}
```

If you also needed local setup, keep a parameterless `__construct()` —
that is still allowed.

## Quick decision guide

Ask these three questions in order:

1. **Is this class container-managed?** (any of the attributes listed above)
   - No → constructor usage is unrestricted. Stop.
   - Yes → continue.
2. **Does `__construct` have parameters?**
   - No → fine, the rule does not trigger.
   - Yes → continue.
3. **What are those parameters for?**
   - Dependencies from the container → forbidden. Move them to
     `#[InjectAs*]` / `#[Config]` properties.
   - Anything else → you are almost certainly doing constructor injection in
     disguise. Move the state into properties and let the container fill
     them, or reconsider whether this class should be container-managed at
     all (maybe it should be a value object).

## Where this rule is enforced

- **Static analysis:**
  `Semitexa\Core\PHPStan\Rules\InjectionViaConstructorRule`
  (identifier: `semitexa.injectionViaConstructor`), registered in the root
  `phpstan.neon`.
- **Runtime:** `GraphBuilder::createInstance()` throws `InjectionException`
  when a container-managed class has `__construct` with parameters.
- **CLI lint:** `bin/semitexa semitexa:lint:di` reports the same violation
  with matching wording.

All three emit messages that explicitly call this out as constructor
*injection*, not constructors in general.

## Common misreadings to avoid

| Misreading | What it actually says |
|---|---|
| “I can’t declare `__construct` at all.” | You can. Just don’t use it to receive dependencies on a container-managed class. |
| “Value objects need setters because constructors are banned.” | They are not banned on value objects. Use normal constructors with validation. |
| “My `ExampleException` can’t have a constructor.” | Exceptions are not container-managed. Constructors are unrestricted. |
| “Payload and resource DTOs must use property injection too.” | No. Only container-managed classes follow the One Way rule. DTOs use normal PHP. |
| “I need to delete my parameterless `__construct` to pass the lint.” | You don’t. The rule only triggers when `__construct` has parameters. |

## Related

- [ARCHITECTURE.md](ARCHITECTURE.md) — §4 "Service Contracts & DI"
- `packages/semitexa-core/src/Container/README.md` — container mechanics
- `packages/semitexa-core/docs/SERVICE_CONTRACTS.md` — contract registration
- `packages/semitexa-core/docs/attributes/AsRequestHandler.md` — handler DI
