---
id: rendering/field-types
section: rendering
slug: field-types
title: Field Types
summary: One field declaration — Field::email('contact') — is the form control, its validation rules, the stored value, the grid column and the filter; built-in types plus your own with #[AsFieldType].
order: 135
locale: en
status: canonical
verified_against: 2026.10.03.1952
keywords:
  - Field::text()
  - "#[AsFieldType]"
  - UiFieldTypeInterface
  - UiFieldSet
  - ui_field_props()
  - platform-ui:field-types
---

# Field Types

A field is declared once, and its type decides how that declaration behaves everywhere:

```php
use Semitexa\PlatformUi\Domain\Model\Field\Field;

Field::text('title')->required()->set('max', 160)->searchable()->sortable();
Field::choice('status', [
    'draft' => ['label' => 'Draft', 'tone' => 'warning'],
    'published' => ['label' => 'Published', 'tone' => 'success'],
])->required()->filterable();
Field::datetime('updatedAt')->sortable()->readOnly();
```

| The type supplies | For example (`email`) |
| --- | --- |
| the form control (`platform.field` props) | `<input type="email" autocomplete="email">` |
| the validation rules | `email` (checked as the field is edited, through HUG, and again on save) |
| the stored value | trimmed, lowercased, `null` when empty |
| the grid column | format `text` |
| the filter | operators `contains`, `eq` (a number, date or moment: the range `gte` / `lte`, shown as "from" and "to") |
| an inference from a stored column | a `varchar` named `*email*` |

`bin/semitexa platform-ui:field-types` lists every type with these details, and `--json` gives
the same list to tools and agents. The demo is `/ui-playground/components/fields`.

## Built-in types

| Type | Control | Column | Notes |
| --- | --- | --- | --- |
| `id` | — | mono | the primary key: read-only, never on a form |
| `text` | input | text | `max` → `maxlength` + `maxLength` rule |
| `textarea` | textarea | — | hidden from lists by default; `rows`, `max` |
| `slug` | input | mono | `slug` rule, lowercased |
| `email` | input:email | text | `email` rule, lowercased |
| `url` | input:url | link | `url` rule: absolute **http(s)** only |
| `integer` | input:number | number | `integer` rule; `min`, `max` |
| `decimal` | input:number | number | `number` rule with `scale`; kept as a **string**, never a float |
| `boolean` | switch | ✓ / – | yes/no filter |
| `choice` | segmented (≤ 4) or select | badge | `in` rule; an option's `tone` colours its badge; `control` overrides |
| `multiChoice` | checkboxes (or multi-select over 8) | — | `in` rule on every value |
| `date` | input:date | date | `date` rule (a real calendar day); stored as `Y-m-d` |
| `datetime` | input:datetime-local | date and time | `datetime` rule; **UTC end to end** (see below) |
| `belongsTo` | select | text | the records are data: the caller supplies them as `options()` |
| `belongsToMany` | checkboxes (or multi-select) | — | as above |
| `file`, `image` | upload | — | the value is the one-time upload ticket; `accept`, `maxBytes` |
| `json` | — | mono | read-only |

An optional `select` (a `belongsTo`, or a `choice` shown as a list) starts with "— None —", so a
chosen value can be cleared.

### Time zones

`datetime-local` carries no zone. A `datetime` field reads what is typed as UTC, which is the
framework's invariant, and says "In UTC." under the control. The grid shows the stored moment
formatted for the visitor's locale, also in UTC, with the zone named. A zoneless stored value
(`2026-05-31 06:39`) is read as UTC rather than as the browser's local time.

## Rendering a form from fields

```twig
{% for field in fields %}
    {{ component('platform.field', ui_field_props(field, record[field.name] ?? null)) }}
{% endfor %}
```

`ui_field_props()` returns the type's props plus the value, so the rules travel with the field.
In the save action, `UiFieldTypes::for($field)->cast($field, $context->values[$field->name] ?? null)`
gives the value to store.

## The grid contract

`UiFieldSet` holds a screen's fields. `contractUi()` is the route contract's `ui` block:

- **columns:** in order, with formats and badge variants;
- **filters:** one for every `filterable()` field;
- **lists:** `sortable()` and `searchable()` give the field lists for the collection block.

The grid renders the `ui` block instead of guessing columns from field names.

## Rules

The types use the built-in rules `required`, `minLength`, `maxLength`, `sameAsField`, `email`,
`url`, `integer`, `number` (an optional scale), `min`, `max`, `slug`, `date`, `datetime` and `in`.
`date` and `datetime` refuse a day that does not exist (`2026-02-30`) and words such as `tomorrow`.
- **Empty values pass.** Every rule lets an empty value through, so emptiness belongs to
  `required` alone.
- **`in` checks every value.** On a checkbox group or a multi-select it checks each selected
  value, because what a select shows is not what a crafted request can send.

## Your own type

```php
#[AsFieldType]
final class StarsFieldType extends AbstractUiFieldType
{
    public function name(): string { return 'stars'; }
    public function description(): string { return 'A rating from 1 to 5.'; }
    protected function controlProps(UiField $field): array
    {
        return ['control' => 'segmented', 'options' => [/* 1…5 */]];
    }
    protected function typeRules(UiField $field): array { return ['integer', ['min', 1], ['max', 5]]; }
}
```

Use it as `Field::of('rating', 'stars')`. A name that repeats a built-in or another project type
fails boot; it never shadows the other type silently.
