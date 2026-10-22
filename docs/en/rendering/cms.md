---
id: rendering/cms
section: rendering
slug: cms
title: CMS
summary: Describe a site as a map of places, let an author edit its records from the OS console, and render them with search and sharing metadata the CMS writes for you.
order: 145
locale: en
status: canonical
verified_against: 2026.10.20.0340
keywords:
  - semitexa/cms
  - AsSiteMap
  - AsContentEditor
  - AsContentCollection
  - ContentField::BLOCKS
  - cms_seo
  - cms_seo_head
  - cms:map:check
  - site map
---

# CMS

After this page you can make your module's records editable from the Semitexa OS console, render them on the public site, and keep their search metadata current.

**Prerequisites.** `semitexa/cms` is not part of the installer's set. Add it from the project root:

```bash
docker compose run --rm --no-deps --user "$(id -u):$(id -g)" app composer require semitexa/cms
bin/semitexa server:restart
bin/semitexa orm:sync
```

Composer brings `semitexa/os` and `semitexa/weave` with it. The SEO and translation work runs on the scheduler worker (`bin/semitexa scheduler:work`). SEO writing also needs a model provider configured for `semitexa/llm`.

## What the CMS manages

The CMS does not store your content. Pages, events and articles stay in your module's own tables, and those tables render the public site. The CMS owns four things around them:

| What | Where it lives |
|---|---|
| The **map**: which records are places someone navigates to | Nodes in the OS graph (`semitexa/weave`), source `cms:map` |
| Search and sharing **metadata** per record | Table `cms_content_seo` |
| The **translation queue** | Table `cms_translation_task` |
| **Images** an author uploads | Media collection `cms:content` (JPEG, PNG, WebP, up to 12 MiB, one `content` variant 1600 px wide) |

Your module supplies the pieces, each found by attribute:

| Attribute | Implements | Answers |
|---|---|---|
| `#[AsSiteMap]` | `SiteMapProviderInterface` | Which records are places, and how they nest |
| `#[AsContentEditor]` | `ContentEditorInterface` | The fields of one record, and how to save them |
| `#[AsContentCollection]` | `ContentCollectionInterface` | The rows behind a list |
| `#[AsContentTranslator]` | `ContentTranslatorInterface` | Optional: keeps a record's other languages in step |

All four are in `Semitexa\Cms\Attribute` and `Semitexa\Cms\Domain\Contract`.

## 1. Describe the map

A place is not a record. A site may have forty page rows of which nine are places; the rest belong in a list. So the map is written by the module, not derived from a table.

```php
use Semitexa\Cms\Attribute\AsSiteMap;
use Semitexa\Cms\Domain\Contract\SiteMapProviderInterface;
use Semitexa\Cms\Domain\Model\Place;

#[AsSiteMap]
final class SiteMap implements SiteMapProviderInterface
{
    public function siteRef(): string { return 'site'; }
    public function siteTitle(): string { return 'Our Site'; }
    public function workTitle(): ?string { return null; }

    public function watches(): array
    {
        return ['site_pages'];          // the table your page repository writes
    }

    public function places(): iterable
    {
        yield Place::site('site', 'Our Site');
        yield Place::page('site:page:about', 'About us', parentRef: 'site', editor: 'site:page', order: 1);
        yield Place::collection('site:events', 'Events', source: 'site:pages?type=event', parentRef: 'site', order: 2);
    }
}
```

- **`Place::page(ref, title, parentRef, editor, properties, order)`** opens an editor. `editor` must match an `editorId()`.
- **`Place::collection(ref, title, source, parentRef, properties, order)`** opens a list. `source` is required (an empty one throws). The part before `?` must match a collection's `sourceId()`. The query part is passed to the collection, unparsed by the CMS, as `$filters`.
- **`ref`** is the record's identity. It is what keeps a place the same place after a rename. An empty ref throws.
- **`watches()`** lists resource keys, which are table names unless a resource declares `#[ResourceKey]`. Return `[]` and the map changes only when someone runs `cms:map:build`.
- **`workTitle()`** hangs the site under an organisation node in the person's graph. `null` leaves it unanchored.

The site node is written from `siteRef()` and `siteTitle()`; yielding `Place::site(...)` as well is optional. A place with no `parentRef` hangs directly under the site. A `parentRef` naming a place the map does not yield is reported `orphan_parent` by `cms:map:check`, and the place is hung under the site meanwhile.

## 2. Make a record editable

```php
use Semitexa\Cms\Attribute\AsContentEditor;
use Semitexa\Cms\Domain\Contract\ContentEditorInterface;
use Semitexa\Cms\Domain\Model\ContentDraft;
use Semitexa\Cms\Domain\Model\ContentField;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Platform\Settings\Domain\Contract\SettingsStoreInterface;

#[AsService]
#[AsContentEditor]
final class PageEditor implements ContentEditorInterface
{
    #[InjectAsReadonly]
    protected SettingsStoreInterface $settings;   // a real module uses its own repository

    public function editorId(): string { return 'site:page'; }

    public function load(string $ref): ?ContentDraft
    {
        if (!str_starts_with($ref, 'site:page:')) {
            return null;                           // not ours: the console tries the next editor
        }
        $row = $this->settings->get('site-pages', $ref);
        if (!is_array($row)) {
            return null;                           // no such page: cms:map:check reports record_missing
        }

        return new ContentDraft(
            ref: $ref,
            title: $row['title'] ?? 'Untitled',
            fields: [
                ContentField::line('title', 'Title', $row['title'] ?? '', required: true),
                ContentField::image('cover', 'Cover', $row['cover'] ?? ''),
                ContentField::blocks('body', 'Page', $row['body'] ?? ''),
            ],
            publicUrl: '/' . substr($ref, strlen('site:page:')),
        );
    }

    public function save(string $ref, array $values): void
    {
        $title = trim($values['title'] ?? '');
        if ($title === '') {
            throw new \InvalidArgumentException('The title cannot be empty.');
        }
        $this->settings->set('site-pages', $ref, [
            'title' => $title,
            'cover' => $values['cover'] ?? '',     // '' means the author removed it
            'body' => $values['body'] ?? '',
        ]);
    }
}
```

Every field value is a string. The six kinds:

| Kind | Factory | The author sees | `save()` receives |
|---|---|---|---|
| `LINE` | `ContentField::line()` | one-line input | the text |
| `TEXT` | `ContentField::text()` | plain textarea | the text, no markup rules |
| `HTML` | `ContentField::html()` | rich text editor | markup already reduced to the allowlist |
| `IMAGE` | `ContentField::image()` | picker and preview | a media asset id, or `''` when cleared |
| `DATE` | `ContentField::date()` | native date picker | `YYYY-MM-DD`, or `''` when cleared |
| `BLOCKS` | `ContentField::blocks()` | a page of text and image blocks | one string the CMS owns; do not parse it |

Each factory takes `(name, label, value = '', required = false, hint = '')`; the third parameter is named `assetId` on `image()` and `date` on `date()`. An unknown kind renders as a textarea. A date range is two `DATE` fields. Relations and lists of other records are not supported.

What the save route does before it calls `save()`:

- Only fields the draft declares are judged. Fields you did not declare may still arrive in `$values`; ignore them.
- A `required` field that is empty (judged by visible content, so `<div><br></div>` is empty and a lone image is not) is refused. A `DATE` that is not a real calendar day is refused.
- `HTML` fields, and the text inside every `BLOCKS` text block, go through an allowlist: `div`, `br`, `blockquote`, `h1`, `pre[language]`, `ul`, `ol`, `li`, `strong`, `em`, `del`, `a[href]`, `figure`, `figcaption`, `img[src alt width height]`. No `class`, `style`, `id` or `on*`. Links may use `http`, `https`, `mailto`, `tel` or a relative address. An image must point at `/os/app/cms/media/<assetId>` or at this tenant's media public prefix; any other image is dropped and the author is told.
- A rich value over 200 000 bytes is refused whole.
- `LINE` and `TEXT` are **not** sanitised. Escape them when you print them; Twig's default escaping does.
- An `\InvalidArgumentException` from `save()` is shown to the author as the error, so write it for them. Any other exception shows a generic failure.
- When `ContentDraft::$publicUrl` is set, the editor shows a link to the live page.

## 3. Lists

```php
use Semitexa\Cms\Attribute\AsContentCollection;
use Semitexa\Cms\Domain\Contract\ContentCollectionInterface;
use Semitexa\Cms\Domain\Model\ContentRow;
use Semitexa\Cms\Domain\Model\ContentRows;
use Semitexa\Core\Attribute\AsService;

#[AsService]
#[AsContentCollection]
final class EventList implements ContentCollectionInterface
{
    public function sourceId(): string { return 'site:pages'; }

    public function rows(array $filters, int $page, int $perPage): ContentRows
    {
        // $filters is ['type' => 'event'] for the source 'site:pages?type=event'
        $rows = [new ContentRow(ref: 'site:page:open-day', title: 'Open day', meta: ['12 Nov'])];

        return new ContentRows(title: 'Events', rows: $rows, total: 1, page: $page, perPage: $perPage);
    }
}
```

The console asks for 25 rows a page. A row is opened by asking each editor of the tenant to `load()` its ref, so rows need no place on the map.

Two capabilities are opt-in, and the console shows the button only where the class implements them:

- **`ContentCreatorInterface::create(array $filters): string`**: create an empty record and return its ref. The module mints the ref. `$filters` comes from the source, so a list filtered by `type=event` creates an event.
- **`ContentRemoverInterface::remove(string $ref): void`**: take the record off the site. Archive or delete is your choice.

Both may throw `\RuntimeException`; the console shows the message.

## 4. Render it on the public site

The CMS does not render your site. Your handler loads the record and tells the CMS which ref the page shows, so the metadata reaches the head:

```php
use Semitexa\Cms\Application\Service\ContentBlockCodec;
use Semitexa\Cms\Application\Service\ContentBlockRenderer;
use Semitexa\Cms\Application\Service\ContentSeoHead;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Ssr\Application\Service\Seo\SeoMeta;

#[InjectAsReadonly]
protected ContentSeoHead $seo;

// in handle():
SeoMeta::setTitle($draft->title);   // the page's own title wins
$this->seo->apply($ref);            // fills description, og:*, robots as defaults

$body = (new ContentBlockRenderer())->render((new ContentBlockCodec())->decode($storedBody));
return $resource->withPage($draft->title, $body);   // a method of your own HtmlResponse subclass, not a CMS API
```

```twig
<head>
    <title>{{ page_title() }}</title>
    {{ meta('description') }}
    {{ meta('og:title') }}
    {{ meta('og:description') }}
    {{ meta('og:image') }}
    {{ meta('robots') }}
    {{ cms_seo_head() }}
</head>
<body>
    <h1>{{ title }}</h1>
    {{ body|raw }}  {# reduced to the allowlist when it was saved #}
</body>
```

- **`ContentSeoHead::apply($ref)`** writes into `SeoMeta` as defaults. A value the page already set is kept. The title goes in only when no title is set yet.
- **`{{ cms_seo(ref) }}`** does the same from a template and prints nothing. Call it above the `meta()` calls. A handler is better: the document title is often rendered before a template function runs.
- **`{{ cms_seo_head() }}`** prints the canonical link and the JSON-LD block for the ref that was applied, and nothing when no ref was applied. Stored JSON-LD that does not parse is dropped.

**Blocks.** `ContentBlockCodec::decode()` never throws. A value that is not a blocks document (a page written before the format, or plain HTML) comes back as one text block holding exactly what was there, and is written in the new shape on its next save. A text block with the default layout renders as its HTML alone, so an old page renders unchanged. A block with a chosen layout is wrapped as `<div class="cms-block" data-align="center" data-size="medium">…</div>`. An image block renders as `<figure class="cms-block" data-align=… data-size=…><img src="/os/app/cms/media/<assetId>" alt="…"></figure>`. Alignment is `left`, `center` or `right`; size is `small`, `medium` or `full`; the default is `left`/`full`.

A template can print the stored field directly with `{{ cms_blocks(body) }}`: it decodes and renders it (a page written before blocks prints as it was, less what the display sanitiser takes out), and when a block carries a layout it requires the default block stylesheet `cms:css:content-blocks` — centred blocks, `small` (20rem) and `medium` (36rem) widths, `left`/`right` floats beside the text from 40rem up and on their own line below. Rendering in the handler, as above, add `{{ asset_require('cms:css:content-blocks') }}` to the template yourself. Every rule of that sheet sits in `:where()`, so any `.cms-block` rule of your skin overrides it whatever the order. Print the blocks inside an element with `display: flow-root`, or a float at the end of the page spills out of it.

`cms_blocks()` prints the field as markup, sanitised again as it prints (`ContentHtmlSanitizer::forDisplay()`): the save is not the only way into a row, and rows saved by an earlier release, imports and direct writes never passed the editor's sanitiser. The display allowlist keeps the elements a page is written in (paragraphs, headings, lists, tables, figures, links and images over http, https, mailto and tel) and drops scripts, event handlers, frames, forms, `style`, `class`, `id` and `name`; every link gets `rel="noopener noreferrer"`. An image from any host still prints: only the editor holds images to the configured sources, so a row written another way can carry an outside image. A field you print yourself with `|raw` gets none of this: clean it with `ContentHtmlSanitizer` when you write it, or print it through `cms_blocks()`. A blocks document this version cannot read (a newer editor's) is kept as it is and renders as nothing.

**Images.** `GET /os/app/cms/media/{assetId}` is public. It answers only for assets in the `cms:content` collection (anything else is 404), redirects to the asset's URL, or serves the bytes itself when the storage has no public URL (the local driver without `STORAGE_LOCAL_PUBLIC_URL`). It asks for the `content` variant first and falls back to the original. Store the asset id, never the URL.

## SEO metadata

Each record has one row of metadata. The editor shows it under the fields:

| Field | Generated | On the form |
|---|---|---|
| `title`, `description`, `ogTitle`, `ogDescription` | yes | yes |
| `jsonLd` | yes | no |
| `ogImage`, `canonical`, `robots` | never | yes |

A field is **generated** until a person types into it. A generated value shows as a grey placeholder with the box empty, so an unrelated save does not claim it. Text a person typed is never overwritten. Clearing it hands the field back to the generator.

When it is written:

1. A save in the console fingerprints the record (title plus every field value) and sets the record due **10 minutes** later. Each further save pushes the deadline out. A save with a fingerprint already described cancels the pending work.
2. The scheduled job `cms.seo` (`CMS_SEO_CRON`, default `*/5 * * * *`, once per tenant, skips if still running) writes up to 10 due records a tick. An install without the scheduler runs `cms:seo:drain` from cron.
3. The drain reloads the record, asks the model only for the fields still open, cuts titles at 70 characters and descriptions at 160 on a word boundary, and drops JSON-LD that does not parse. A record whose field values are all empty is not described.
4. A failure retries after 30 minutes and stops after 5 attempts. A record whose editor or row is gone is forgotten.

The prompt is `cms.seo.write`. Read it with `bin/semitexa prompt:show --id=cms.seo.write` and override it per tenant.

## Translations

A module that keeps other languages registers `#[AsContentTranslator]` with `translatorId()` equal to its editor's `editorId()`. After each console save the record is queued with `fingerprint($ref)` and the same 10-minute window. The `cms.translate` job (`CMS_TRANSLATE_CRON`, default `*/5 * * * *`, per tenant) or `cms:translate:drain` calls `translate($ref)`. Any exception from `translate()` retries after 30 minutes, up to 5 attempts. The CMS never translates text itself, and it never sees what your translator writes: **treat a model's translation as untrusted markup**. An author's sanitised text can still ask the model to add a script, so run every HTML value the model returns through `ContentHtmlSanitizer::sanitize()` before you store it, or print the overlay only through `cms_blocks()`.

## Where an author edits

In the OS console, in the **Content** app at `/os/app/cms`. With no ref it lists every page and collection on the map. The assistant reaches it through two skills: `Content` opens a page by `name` (an exact title wins, a partial match lists the candidates) or by `ref`; `content-list` lists the map, or with `collection` the rows of a list, 20 at a time.

| Route | Method | Access |
|---|---|---|
| `/os/app/cms` | GET | console |
| `/os/app/cms/save` | POST | console |
| `/os/app/cms/create` | POST | console |
| `/os/app/cms/delete` | POST | console |
| `/os/app/cms/media` | POST (multipart) | console |
| `/os/app/cms/media/{assetId}` | GET | public |

The console routes are content surfaces: when the console requires sign-in, Owner and Admin open them, and so does an **Editor**, who can open content surfaces only — the CMS and any other surface a package marks as content — and not the rest of the console.

A page opens by its ref without the map: the console asks each editor of the tenant to `load()` it. The map is needed for collections (a list opens only from its node), for the chooser shown with no ref, for opening a page by `name` and for `content-list`. Build it (below) before authors start.

## How the map stays current

`cms:map:build` projects every map of the current tenant into the graph. It is safe to re-run: places are matched by ref.

After that, a write to a table a map `watches()` re-projects that map synchronously, at most once per site per request or job. A failed re-projection is ignored; the content write already happened, and the next change or a `cms:map:build` repairs the map. A write the ORM does not announce, such as raw SQL, does not move the map.

A rebuild respects what a person did in the graph:

- A title or order a person changed is kept. The module still owns what a place is: its kind, editor and source.
- A place a person removed is not created again while the map keeps claiming it.
- A place the map no longer claims is reported as stale and left in place, never deleted. Only refs that start with `<siteRef>:` are considered, so give every place that prefix.

## Multi-tenant installs

Every attribute takes `tenant`. `#[AsSiteMap(tenant: 'museum')]` is seen only when the current tenant is `museum`. An empty `tenant` (the default) is seen by **every** tenant. The same rule applies to editors, collections and translators, so a ref typed into a URL cannot reach another site's records. The graph, `cms_content_seo` and `cms_translation_task` are tenant-scoped.

The scheduled jobs run once per tenant. The console commands run in the ambient tenant, so a map declared for a tenant is skipped until you run it there:

```bash
bin/semitexa tenant:run museum cms:map:build
```

`cms:map:build` names the tenants whose maps it skipped. `cms:map:check` names them only when the current tenant has no map at all.

## Commands

| Command | Options | Does |
|---|---|---|
| `cms:map:build` | `--dry-run` | Projects each map into the graph; reports places, edges, stale and removed refs |
| `cms:map:check` | `--snapshot=<file>`, `--compare=<file>`, `--json` | Asks of every place whether the console can open it; compares against a snapshot |
| `cms:seo:drain` | `--batch` (10) | Writes metadata for records whose window has closed |
| `cms:translate:drain` | `--batch` (10) | Translates records whose window has closed |

`cms:map:check` reads the maps from code. It does not need `cms:map:build` and never repairs anything. Each place gets a verdict: `reachable`, `editor_missing`, `record_missing` (the editor's `load()` returned null), `source_missing`, `orphan_parent`, `unreachable` (no walk down from the site arrives, as with two places naming each other as parent) or `duplicate_ref`. It does not check that the public URL answers.

Take a snapshot before moving a site, compare after:

```bash
bin/semitexa cms:map:check --snapshot=var/map-before.json
# ... move the site ...
bin/semitexa cms:map:check --compare=var/map-before.json
```

The comparison reports places gone, new, renamed, moved, with a changed verdict or a changed editor or source, a site gone, and site head values (an analytics id, a verification token) gone or changed. With both options on the same file it reads the old snapshot before it writes the new one. Exit code `0` means no problems and no changes; anything else is `1`. The `--json` artifact is `semitexa.cms.map-check/v1`.

## Known limits

- Metadata and translations are queued only by a save in the console. A record written by your module, an import or the assistant's own skills is not queued.
- Removing a record does not delete its metadata row. The drain drops it only if the row is still due when the record is gone.
- A blocks page has two block kinds, text and image. A blocks document with a kind this version does not know is kept as it was, shown as one raw text block.
- Field values are strings only; there is no relation field.
- The console speaks English and Ukrainian (`src/Application/View/locales`), following the OS language; another language falls back to English.
- Metadata generation needs a working model provider; without one every due record fails five times and stops.

## Next steps

- [SEO](seo.md): the `SeoMeta` store and the `meta()` calls the CMS fills.
- [Tenant Context Resolution](../platform/tenancy-resolution.md): how the tenant the CMS scopes by is chosen.
- [cms commands](../reference/commands-cms.md) and [Cms attributes](../reference/attributes-cms.md): the generated reference.
