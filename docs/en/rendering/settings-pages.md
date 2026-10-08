---
id: rendering/settings-pages
section: rendering
slug: settings-pages
title: Settings Pages
summary: An editable settings page in one class — fields with defaults, stored per tenant in platform-settings, validated by the field rules, grouped into sections, read back typed.
order: 140
locale: en
status: canonical
verified_against: 2026.10.03.1952
keywords:
  - "#[AsSettingsPage]"
  - SettingsDefinition
  - SettingsValues
  - settings.save
---

# Settings Pages

A settings page is one class. Its fields say what can be set, and each field's default is the
value until someone saves another:

```php
#[AsSettingsPage(
    id: 'playground.store',               // where the values are stored, and the route name
    path: '/playground/settings/store',
    title: 'Store',
    group: 'playground.store',            // the settings area this page is a section of
    description: 'How the shop presents itself, and when stock counts as low.',
    permission: 'settings.manage',
    nav: 'Account',
    layout: '@project-layouts-Playground/layouts/app.html.twig',
)]
final class StoreSettings extends SettingsDefinition
{
    public function fields(): array
    {
        return [
            Field::text('storeName')->required()->set('max', 80)->default('Semitexa Shop'),
            Field::choice('currency', ['EUR' => 'Euro', 'USD' => 'US dollar'])->required()->default('EUR'),
            Field::integer('lowStockThreshold')->required()->set('min', 0)->default(5),
            Field::boolean('maintenance')->default(false),
        ];
    }
}
```

Read a value where you need it:

```php
$threshold = $this->settings->of(StoreSettings::class)['lowStockThreshold'];   // SettingsValues
```

## What the class gives you

| | |
| --- | --- |
| The page | a protected route rendered on `@platform-ui/pages/settings`, with one form |
| Sections | every page of the same `group` is a section of one settings area, ordered by `order`, each listed only for a visitor who may open it |
| Saving | the `settings.save` form action, with the page signed into the form; the field rules run on the server, then `check()`, then each field is stored |
| Storage | `semitexa/platform-settings`, one setting per field under the page's id, for the request's tenant |
| Reading | `SettingsValues::of()`: what was saved, else the field's default |
| Navigation and Ctrl+K | `nav:` lists the page through `crud_nav()`, and the palette finds it by its title |

A page with a `permission` requires it, both to see the page and to save it. Without one, any
signed-in visitor may do both. Values that are per visitor (a profile, a preference) are not
settings pages: store them per user.

## Rules across fields

```php
public function check(array $values): array
{
    return $values['lowStockAlert'] === true && $values['alertEmail'] === null
        ? ['alertEmail' => 'Say where the alert should go.']
        : [];
}
```

The answer shows on the field, and nothing is saved.

## When a change is seen

The worker that saved sees the change at once. Other workers keep a snapshot of a module's
settings for up to two seconds (`SettingsModuleSnapshots::TTL_SECONDS`), so they see it within
that time.

The Playground's Store and Notifications pages (`/playground/settings/store`) are one settings
area. The dashboard's Low stock widget reads the threshold from it.
