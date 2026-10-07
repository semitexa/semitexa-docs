---
id: rendering/dashboards
section: rendering
slug: dashboards
title: Dashboards
summary: Widgets registered by attribute — a stat with a trend, recent records, a small SVG chart — each behind its own permission, laid out by platform.dashboard; ready-made widgets for CRUD screens.
order: 139
locale: en
status: canonical
verified_against: 2026.10.03.1952
keywords:
  - "#[AsDashboardWidget]"
  - platform.dashboard
  - platform.chart
  - UiWidget
  - RecordCountWidget
  - RecentRecordsWidget
  - UiWidgetWatchesInterface
  - UiIslandInterface
---

# Dashboards

A dashboard is a page of widgets, and each widget is one class:

```php
#[AsService]
#[AsDashboardWidget(dashboard: 'admin', order: 10, permission: 'orders.read')]
final class OpenOrders implements UiDashboardWidgetInterface
{
    #[InjectAsReadonly]
    protected OrderStats $stats;

    public function widget(): UiWidget
    {
        return UiWidget::stat('Open orders', (string) $this->stats->open(), delta: '+12%', trend: 'up',
            series: $this->stats->openPerDay())   // ['Oct 5' => 3, 'Oct 6' => 5, …]
            ->withLink('/admin/orders', 'All orders');
    }
}
```

```twig
{{ component('platform.dashboard', {name: 'admin'}) }}
```

## What a widget can show

| Shape | Made with | Drawn as |
| --- | --- | --- |
| A number | `UiWidget::stat(label, value, delta?, trend: up\|down\|flat, series?, caption?)` | `platform.stat`, with the series as a sparkline under it |
| Records | `UiWidget::list(title, [{title, href?, meta?}], empty)` | `platform.list` |
| A chart | `UiWidget::chart(title, 'line' \| 'bars', [{label, value}])` | `platform.chart` |

`->withLink(href, text)` adds a link at the foot of the card.

## Who sees what

- **Permission.** `permission:` on the attribute shows the widget only to a visitor who holds it. A widget the visitor may not see is never resolved and never computed. A widget can also state its own permission (`UiWidgetPermissionInterface`); the CRUD widgets below take their screen's read permission this way.
- **Order and width.** Widgets are ordered by `order`. `wide: true` spans two columns where the dashboard is wide enough.
- **Failure.** A widget that throws is shown as unavailable, and the error is logged. The rest of the page renders.
- **Freshness.** The dashboard is never cached: it is computed per visitor and per moment.

## Live

A widget that says what it reads is redrawn when that is written, on every open dashboard:

```php
final class OpenOrders implements UiDashboardWidgetInterface, UiWidgetWatchesInterface
{
    public static function watches(): array
    {
        return [ResourceMetadata::for(OrderResource::class)->getResourceKey()];
    }
    …
}
```

- **What it watches.** The keys are invalidation scopes. A model's key (its table name, unless
  `#[ResourceKey]` says otherwise) is published by every ORM write to it. A raw write publishes
  one with `ScopeInvalidatorInterface::touch()`.
- **How.** `platform.dashboard` is an [island](components.md#islands-live-components): it
  watches what the visitor's widgets read, and no more. On a write it is drawn again for that
  visitor, with the same permissions as the page, and morphed in place, so a card whose content
  did not change is left as it is.
- **Guests** see the dashboard as it was drawn. The island feed is a protected route.
- **Not watched:** what a widget reads but does not declare, a setting for example. The Low
  stock widget follows product writes, not a change to its threshold.

## Charts without a library

`platform.chart` is SVG computed on the server: no script, no chart library. Values are scaled from zero, so equal values draw equal heights, and a zero is still a hairline.

Each chart is an image with a name and a one-line summary ("Sep 23 to Oct 6: from 0 to 3, highest 5"). Its numbers are also a visually hidden table, so a screen reader can read them one by one. It works on any page:

```twig
{{ component('platform.chart', {title: 'Orders this week', kind: 'bars', points: [{label: 'Mon', value: 3}, …]}) }}
```

## Widgets for CRUD screens

```php
#[AsService]
#[AsDashboardWidget(dashboard: 'admin', order: 20)]
final class ProductCount extends RecordCountWidget
{
    public static function screen(): string { return ProductCrud::class; }
}
```

- **`RecordCountWidget`** shows how many records the screen holds. Its sparkline counts records created per day: `countByDay()` on the model's created column, one query. Its delta compares this week with last. Override `narrow()` to count only some records, or `days()` for the window.
- **`RecentRecordsWidget`** lists the records changed last (by the updated column, else created). Each item links to the record's view dialog.

Both read through the screen's `query()` and the request's tenant, are shown only to who may read the screen, link to it, and watch its model, so they are live.

The Playground overview (`/playground`) is a dashboard: articles for everyone, and products behind `catalog.read`.
