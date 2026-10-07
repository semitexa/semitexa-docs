---
id: rendering/crud-screens
section: rendering
slug: crud-screens
title: CRUD Screens
summary: A list, live grid, view, create and edit dialogs, delete, row/bulk/header actions, permissions, navigation and Ctrl+K over one ORM model — one #[AsCrud] class with a field list.
order: 138
locale: en
status: canonical
verified_against: 2026.10.03.1952
keywords:
  - "#[AsCrud]"
  - CrudDefinition
  - semitexa/crud
  - crud.save
  - crud.delete
  - crud_nav
  - "#[AsCrudAction]"
  - CrudAction
---

# CRUD Screens

A CRUD screen is where an administrator lists, finds, creates, edits and deletes the records of
one model. With `semitexa/crud` it is one class:

```php
use Semitexa\Crud\Application\Payload\Request\CrudDefinition;
use Semitexa\Crud\Attribute\AsCrud;
use Semitexa\PlatformUi\Domain\Model\Field\Field;

#[AsCrud(
    id: 'playground.products',            // the screen's id and route name
    path: '/playground/products',
    model: PlaygroundProductResource::class,
    label: 'Product',                     // plural: "Products" (or plural: '…')
    permission: 'catalog',                // catalog.read / .create / .edit / .delete
    nav: 'Content',                       // the navigation group it is listed under
    icon: 'package',
    layout: '@project-layouts-Playground/layouts/app.html.twig',
)]
final class ProductCrud extends CrudDefinition
{
    public function fields(): array
    {
        return [
            Field::id(),
            Field::text('name')->required()->set('max', 160)->searchable()->sortable(),
            Field::slug('sku')->label('SKU')->required()->searchable(),
            Field::choice('status', [
                'draft' => ['label' => 'Draft', 'tone' => 'warning'],
                'active' => ['label' => 'Active', 'tone' => 'success'],
            ])->required()->default('draft')->filterable(),
            Field::decimal('price')->required()->set('min', 0)->sortable(),
            Field::date('launchDate')->sortable(),
            Field::textarea('notes'),
            Field::datetime('updatedAt')->readOnly()->sortable(),
        ];
    }
}
```

The Playground's Products screen (`/playground/products`) is this class.

## Generate one

```bash
bin/semitexa make:crud --module=Playground --model=PlaygroundCategoryResource --permission=catalog --nav=Content --write
```

This writes the class, with its fields read off the model's columns, and a unit test that the screen boots and every field maps to a column.
- **How columns become fields.** A `varchar` named `email` becomes an email field, `text` a textarea, and `updated_at` a read-only moment. A column named `name`, `title` or `label` is searched and sorted.
- **Left out:** relations, the tenant column and `#[Version]`.
- **Defaults:** the id and path come from the module and the regular plural (`playground.categories`, `/playground/categories`). The permission prefix is the plural, and the nav group is the module, unless `--permission` and `--nav` say otherwise.
- **Next steps** it prints: grant the permissions, and add `crud_nav()` to the layout if it is missing.

The Playground's Categories screen is this command's output, unedited.

## What one class gives you

| Request | Answer |
| --- | --- |
| `GET /playground/products` (a browser) | the page in your layout: the heading, a **New product** button, the live grid |
| `GET` with `Accept: application/json` | the grid's collection: search, sort, filters and paging from the fields, as in a [collection feed](collection-feeds.md) |
| `OPTIONS` | the route contract: the `collection` and `ui` blocks the grid is drawn from |
| `?create` | the page with the create dialog open |
| `?edit=<id>` | the page with that record's edit dialog open, or 404 and a notice when the screen does not hold it |
| `?view=<id>` | a read-only dialog with every field, the ones the list hides included (also what `?edit` shows a visitor who may not edit) |
| live | every ORM write to the model refreshes every open grid, through the one KISS stream |

It is **one route**. The page and the collection are its two render profiles (HTML first, so a
browser's `*/*` gets the page). The grid subscribes to the route by its name through HUG.

The dialogs live in the address, so a create or an edit can be linked to, reloaded, and closed
with back. See [Modal in the address](ui-behaviors.md#modal-in-the-address).

## Permissions

`permission: 'catalog'` gives four permissions:

| Permission | Allows |
| --- | --- |
| `catalog.read` | the page, the collection, the contract, the live feed (the route requires it) and the View dialog |
| `catalog.create` | the New button and the create dialog |
| `catalog.edit` | the Edit row action and the edit dialog |
| `catalog.delete` | the delete control in the edit dialog |

An application whose words are not create / edit / delete names its own per operation:

```php
#[AsCrud(…, permission: 'content', permissions: ['create' => 'content.publish', 'delete' => 'content.publish'])]
```

The page shows only what the visitor may do. That is not the check: every save and every delete
asks the authorizer again, for the visitor who submits it. Without `permission:` any signed-in
visitor may do all four. A guest never gets the page, the rows or the contract.

## Relations

```php
Field::belongsTo('categoryId')->label('Category')->filterable(),   // the model's #[BelongsTo] on category_id
Field::belongsToMany('tags')->filterable(),                        // the model's #[ManyToMany] $tags
```

- **Choices.** A relation field takes its choices from the related model, found through the
  model's own relation metadata. They are labelled by its `name` column, or by another column
  set with `->set('labelProperty', 'title')`, ordered by that label, at most 500. The form
  shows a select, or checkboxes (a multi-select past eight choices).
- **In the grid and the view,** the field shows its choices' labels, not their ids.
- **Filtering.** A `belongsTo` filters as a select of its choices. A `belongsToMany` filters
  through its pivot: the matching ids are found there and the page narrowed to them.
- **Counts.** Each choice says how many records it holds ("PHP (12)"), counted over the screen's
  records, not narrowed by the filters picked now. A `belongsToMany` on a tenant-scoped model, or
  on a screen that narrows `query()`, is not counted, because its pivot would count records the
  screen does not show.
- **Saving** writes the `belongsTo` column. A `belongsToMany` is written whole, to the pivot,
  from the form's checked ids. An id that is no longer a choice (deleted since the form was
  drawn, or never offered) answers as a field error.

The Playground's Articles screen (`/playground/orm/articles`, `ArticleCrud`, 52 lines) has a
category select and tag checkboxes. It replaced 28 hand-written files (1,876 lines) and their
tests.

## Writes

The create and edit forms submit to one action, `crud.save`, and the delete control to
`crud.delete`, both through HUG. The screen's id and the record's id are **signed** into the
form, so the browser can point a form neither at another screen nor at another record.

A save:

1. checks each field's rules on the server, the same rules the browser checked;
2. casts each writable field to what is stored (a slug lowercased, a moment in UTC, a decimal
   kept exact);
3. answers a value another record already holds as a field error, from the model's
   single-column unique indexes ("Another product already has this sku.");
4. runs the screen's `check()`;
5. writes through the ORM's aggregate write engine. The tenant is stamped, and `#[Version]`
   refuses a save that someone else's save overtook. The change event refreshes the grids.

It fills in `createdAt` / `created_at` on create and `updatedAt` / `updated_at` on every save,
when the model has them and no field writes them. An edit keeps every value its form does not
show.

The record is found through the screen's own `query()`, so a screen narrowed to some records
cannot edit or delete the others.

## Actions

A screen's grid offers **Delete** on each row and on a selection, with a confirmation, unless
the screen says otherwise. Add your own actions in `actions()`:

```php
public function actions(): array
{
    return [
        CrudAction::of('activate', 'Activate', ActivateProducts::class)->onRows()->inBulk(),
        CrudAction::delete(),
        CrudAction::of('discontinue-sold-out', 'Discontinue sold-out', DiscontinueSoldOut::class)
            ->inHeader()
            ->confirm('Discontinue every active product with no stock?'),
    ];
}
```

```php
#[AsService]
#[AsCrudAction]
final class ActivateProducts implements CrudActionHandlerInterface
{
    #[InjectAsReadonly]
    protected CrudRecords $records;

    public function run(CrudDefinition $screen, array $records): UiGridActionResult
    {
        foreach ($records as $record) {
            $this->records->save($screen, $record, ['status' => 'active']);
        }

        return UiGridActionResult::done(sprintf('Activated %d products.', count($records)), count($records));
    }
}
```

- **Placement.** `onRows()` puts the action on each row, `inBulk()` on a selection (and adds the checkboxes), and `inHeader()` above the grid, acting on no rows. A header handler finds its own records with `$records->matching($screen, fn ($q) => $q->where(…))`.
- **Permission.** An action needs the screen's `edit` permission unless it says otherwise: `->requires('delete')` names an operation, and `->requires('catalog.publish')` a whole permission. A visitor without it does not see the action, and the server refuses it anyway.
- **Records.** They are found through the screen's `query()`. An id the screen does not hold is skipped, never acted on. A delete removes each record in its own write, and reports any that could not go.
- **Checked at boot.** Every action has a scope and a unique id, and its class carries `#[AsCrudAction]`.

## Making it yours

```php
final class ProductCrud extends CrudDefinition
{
    public function fields(): array { … }

    /** Which records this screen manages. */
    public function query(ResourceModelQuery $query): ResourceModelQuery
    {
        return $query->where(PlaygroundProductResource::column('status'), Operator::NotEquals, 'archived');
    }

    /** How a record is named in a heading (default: its first text field). */
    public function title(object $model, string $id): string
    {
        return $model->name . ' (' . $model->sku . ')';
    }

    /** Rules the field types cannot make: across fields, or against stored records. */
    public function check(array $values, ?object $existing): array
    {
        return $values['status'] === 'active' && (float) $values['price'] === 0.0
            ? ['price' => 'An active product needs a price.']
            : [];
    }
}
```

## Navigation and Ctrl+K

A layout lists every screen that names a `nav:` group with `crud_nav()`. Each screen goes into
the group with that label, or into a new group after the others. Each item carries the
screen's read permission, so the app shell lists it only for a visitor who may open it:

```twig
{% set appNav = crud_nav([
    {label: 'Lab', items: [{label: 'Overview', href: '/playground', icon: 'layout-dashboard'}]},
]) %}
```

The command palette finds every screen by its names ("Products") and offers its create dialog
("New product"), each behind its permission.

From two characters on, it finds records too. Each screen the visitor may read is searched by its
searchable fields, through its `query()` and the request's tenant, as its grid's search box would
search it. A match is listed under the screen's plural, by its `title()`. It opens the record's
edit dialog, or the view dialog for a visitor who may not edit. A screen with no searchable field
is not searched.

## Limits

- **Owned relations** must be typed `array|RelationState` (the ORM refuses a plain array). A
  relation the form does not show is then left exactly as it was when a record is saved.
- **Large relations.** A relation field lists at most 500 choices; a searchable picker is not
  there yet.
