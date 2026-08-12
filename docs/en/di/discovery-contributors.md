---
id: di/discovery-contributors
section: di
slug: discovery-contributors
title: Discovery Contributors
summary: How a package teaches boot-time discovery to recognise its own attribute, with priority ordering and per-class error isolation.
order: 90
locale: en
status: canonical
keywords:
  - AsDiscoveryContributor
  - DiscoveryContributor
  - priority
  - BootDiagnostics
---
# Discovery Contributors

Attribute discovery is extensible. A package that defines its own attribute registers a **contributor** that says which attribute to watch and what to do with each hit — the same mechanism core uses for pipeline listeners, server lifecycle hooks and resource metadata.

```php
#[AsDiscoveryContributor(priority: 50)]
final class SlotHandlerContributor implements DiscoveryContributor
{
    public function attribute(): string
    {
        return AsSlotHandler::class;
    }

    public function scopedToActiveModules(): bool
    {
        return true;
    }

    public function contribute(string $className, object $attribute, BootDiagnostics $diagnostics): void
    {
        // register $className against the registry this package owns
    }
}
```

That is `Semitexa\Ssr\Application\Service\Discovery\SlotHandlerContributor`. The class must implement `DiscoveryContributor` and be instantiable; discovery rejects an `#[AsDiscoveryContributor]` that is not, by name.

## The three methods

**`attribute()`** returns the attribute class to watch. Returning a class that is not loadable is *not* an error — it means the owning package is not installed, and discovery skips the contributor. That is the one place `class_exists()` behaviour is intentional, and it lives here rather than being repeated at every call site.

**`scopedToActiveModules()`** decides visibility. Return `true` — the usual answer — and hits only count when they come from an active module or the project's own `src/`, so a slot declared by a module the current tenant has not enabled stays unregistered. Return `false` for framework-level contributions that apply regardless of tenant configuration.

**`contribute()`** runs once per attribute occurrence, so a class carrying the attribute repeatably is visited once per declaration. **Throwing is safe and expected** for a genuinely invalid declaration: discovery records it against `BootDiagnostics` and continues, so one malformed class cannot abort a boot. Validate loudly rather than skipping quietly.

## Priority

`priority` orders contributors against each other, highest first. The four in `semitexa/ssr` show the reasoning:

| Priority | Contributor | Why there |
|---:|---|---|
| 300 | `LayoutSlotContributor` | layouts declare the slots |
| 200 | `DataProviderContributor` | providers feed them |
| 100 | `SlotResourceContributor` | resources bind to declared slots |
| 50 | `SlotHandlerContributor` | a handler attaches to a slot, so it runs last |

The rule of thumb: whatever declares a thing must run before whatever attaches to it.

## When you need one

Only when your package defines an attribute that needs a boot-time registry. Consuming existing attributes needs nothing — declare the attribute and let its owner's contributor do the work.
