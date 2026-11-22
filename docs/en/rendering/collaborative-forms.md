---
id: rendering/collaborative-forms
section: rendering
slug: collaborative-forms
title: Collaborative Forms
summary: Putting a record's form into platform.collab-form so several people edit it at once, the four modes and what each shows co-editors, presence, how edits travel and conflict, and saving with your own form post.
order: 195
locale: en
status: canonical
verified_against: 2026.11.21.1434
keywords:
  - platform.collab-form
  - FormCollaborationMode
  - FormLock
  - FieldLock
  - presence
  - FormCollaborationDrafts
  - semitexa:collab:reset
  - semitexa:collab:expired
  - "#[CollaborativeForm]"
---

# Collaborative Forms

After this page you can let several people edit one record's form at the same time, choose what
they see of each other, and save the record with your own form post without losing or
resurrecting anyone's edits.

## Prerequisites

- `semitexa/platform-ui`, and its `form_collab_draft` table (created by
  [`bin/semitexa orm:sync`](../data/schema-sync.md)). The shared draft lives there, per tenant.
- A cache every worker sees: `CACHE_DRIVER=redis` ([Cache](../data/cache.md)). Presence, locks
  and save notices live in it. With a per-worker cache each worker has its own roster and locks.
- The page's KISS session. The shell does not announce it: call `{{ ui_page_live_channel() }}`
  on the page, or print `{{ ui_page_sse_session_meta() }}`. Without a session the form's status
  host says `offline` and no co-editor's edit arrives.

## Opting in

A form opts in by being rendered inside `platform.collab-form`. Your inputs go in the `content`
slot, each tagged with `data-ui-field-name`:

```twig
{% set _fields %}
    {{ csrf_field() }}
    <label>Title <input name="title" data-ui-field-name="title" value="{{ order.title }}"></label>
    <label>Notes <textarea name="notes" data-ui-field-name="notes">{{ order.notes }}</textarea></label>
    <button type="submit" formaction="/orders/{{ order.id }}">Save</button>
{% endset %}

{{ ui_page_live_channel() }}
{{ component('platform.collab-form', {
    formKey: 'order',
    recordId: order.id,
    mode: 'field-lock',
    fields: ['title', 'notes'],
}, { content: _fields }) }}
```

| Prop | Meaning |
|---|---|
| `formKey` | The form's stable name, `/^[a-z][a-z0-9_-]*$/`. Required. |
| `recordId` | The record being edited, `/^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$/`. Required. |
| `mode` | `shared`, `optimistic`, `form-lock` or `field-lock`. The shell's default is `shared`. A value that is none of the four runs as `optimistic`. |
| `fields` | The field names the runtime manages. Each must match a `data-ui-field-name` in the slot. |
| `title`, `description`, `ariaLabel` | Optional heading, muted paragraph and group label. |

Everyone who renders the same `formKey` and `recordId` edits one document, the scope
`formdoc:{formKey}:{recordId}` (`FormDocumentScope::forRecord()`). The element carrying
`data-ui-field-name` is the control itself or wraps it: the runtime takes the element when it is
an `input`, `select` or `textarea`, else the first one inside it. It reads and writes the
control's `value` on `input` events; a checkbox's `checked` state is not carried.

The shell signs the scope, the mode and the field list into the page (`ui_collab_manifest()`,
built by `CollabManifestBuilder`). The server takes all three from those signed tokens, never
from the request body, and refuses an edit of a field that is not in `fields` (`unknown_field`).

`#[CollaborativeForm(formKey:, mode:)]` validates a form key and names a `FormCollaborationMode`
on a class of yours. The component does not read it: the props above are what a render uses, so
pass the same key and `$mode->value`. Note the two defaults differ: the attribute's is
`FormCollaborationMode::Optimistic`, the shell's is `shared`.

## The four modes

`FormCollaborationMode` has four cases. Presence is live in all of them.

| Mode | Prop value | Who may write a field | What a co-editor sees |
|---|---|---|---|
| `Optimistic` | `optimistic` | Everyone, on their own copy. Nothing is sent until **Save**. | Other people's saved values until they type themselves; a conflict prompt when their save is stale. |
| `Shared` | `shared` | Everyone. The last write of a field wins. | Each edit as it is typed. |
| `FormLock` | `form-lock` | The one person holding the whole-form lock. | The holder's edits live, every managed field disabled, and `🔒 Locked by <name>`. |
| `FieldLock` | `field-lock` | For each field, the person holding that field's lock. | Edits live; the field someone else is in is disabled. Other fields stay editable. |

The server decides (`FormCollaborationPolicy::canEditField()`): an edit from someone who does
not hold the lock is answered `field_locked` and not stored.

### FormLock

- The first person to focus a managed field asks for the lock (`lock.acquire`). Until the answer
  arrives what they type is queued; the grant sends the field's current value.
- Everyone else's managed inputs are `disabled`, and the shell's `[data-ui-collab-lock]` host is
  shown with `🔒 Locked by <name>` and `data-state="locked"`. They still receive every edit.
- A person who loses the race (`lock_unavailable`) goes read-only at once. Text typed into a
  field they are still in stays in the input, unsent.
- The lock is released when focus leaves the whole component, not when it moves between its
  fields, and only after the last edit has reached the server. A lock granted after focus has
  already left the form is released at once (on `develop`, after 2026.11.21.1434).
- The holder renews the lock every 10 s. A lock lives 30 s without renewal
  (`CacheBackedFormLockStore::LOCK_TTL_SECONDS`), so a holder whose tab closed or lost its
  connection stops blocking the form within that time. There is no forced takeover.

### FieldLock

- Focusing a field asks for that field's lock; leaving it releases the lock once the field's
  last edit has reached the server. A lock granted after focus left the field is released at
  once.
- A field someone else holds is `disabled` and carries
  `data-ui-collab-field-lock="<name>"`. No banner text is written in this mode.
- An edit the server refuses as `field_locked` asks for the lock once more and is sent again on
  the grant. Refused a second time, or with the lock refused, the change is dropped; in a field
  nobody is in, the input goes back to the draft's value.
- Renewal and expiry are as in FormLock, per field.

### A change made without focus

A page script that fills a field and dispatches `input` has no focus to take a lock:

- a field (or form) someone else holds: the change is not sent and the input goes back to the
  draft's value;
- `FieldLock`, a free field: the change takes the lock, is sent, and releases the lock;
- `FormLock`, a free form: the change waits and goes out when this person next takes the lock.
  A script never takes the whole form.

## Presence

The runtime sends `presence.ping` when the form starts and every 15 s. The roster keeps a
participant for 30 s after their last ping (`CacheBackedFormPresenceStore::PRESENCE_TTL_SECONDS`),
so a closed tab disappears within that time. The roster arrives with every snapshot and is drawn
into `[data-ui-collab-presence]` as one `<span class="ui-collab-chip" data-participant-id="…">`
per participant; your own carries `data-self="1"`.

- **Who a participant is.** The signed-in user's id. Without `semitexa/auth`, or signed out, it
  is the render's instance id and the label is `Guest`, so every guest tab is its own participant.
- **The name shown**, in the chip and in "Locked by": your
  `FormCollaboratorLabelResolverInterface` when you bind one, else the user object's
  `getDisplayName()`, then `getName()`, then the id. It is cut to 64 characters and written as
  text. It runs on every ping and lock request, so answer from a cache, and return nothing the
  other editors may not see.

```php
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\PlatformUi\Domain\Contract\FormCollaboratorLabelResolverInterface;

#[SatisfiesServiceContract(of: FormCollaboratorLabelResolverInterface::class)]
final class PartnerLabels implements FormCollaboratorLabelResolverInterface
{
    public function labelFor(string $userId): ?string
    {
        return null; // null or '' falls back to the user object's own name
    }
}
```

## How edits travel

1. A keystroke in a managed field sends `field.edit` (`{field, value}`, the whole value) to
   [HUG](ui-events.md#kiss-and-hug-the-whole-transport). `FormCollaborationEventHandler` merges
   it into the shared draft (`FormCollabDraftStoreInterface::mergeFields()`) and touches the
   document's scope.
2. Every open form of that document is subscribed to the feed `platform-ui.form-doc` on its
   page's KISS stream. The touch re-runs the feed, and each form receives a `ui.document.data`
   snapshot: all draft values, the version, who wrote last, the roster and the locks.
3. The runtime writes the values into the inputs and dispatches `input` on each one it changed,
   with these exceptions: the field you are in, a field whose edit is still on its way, and a
   snapshot you caused yourself.

A field has one edit in flight; behind it only the latest value waits. An edit with no answer in
10 s is aborted. A value that failed (no answer, or a 5xx) is sent once more at once, then on up
to three presence heartbeats, then dropped. A refusal (4xx) is an answer and is not sent again.

The draft is an overlay of unsaved edits on the record your page rendered: the first snapshot a
newcomer receives writes the draft's values over the inputs' rendered ones.

## Conflicts

- **`Shared`, `FieldLock`:** no conflict is raised. Each edit replaces its own field's value and
  the last to arrive wins. Edits carry no sequence number, so an edit aborted after 10 s that the
  server still processes can land behind a newer one.
- **`FormLock`, `FieldLock`:** two people are kept out of one field by the lock. The locks are a
  coordination aid in the cache, not a transaction: your own form post is not checked against them.
- **`Optimistic`:** the shell renders a **Save** button (`[data-ui-collab-save]`). It sends
  `form.save` with every managed value, the draft version and the draft epoch the edits were
  based on. A stale one is refused with `form_draft_version_conflict`, and the shell's
  `[data-ui-collab-conflict]` host shows "Save conflict" with two buttons: **Keep mine** saves
  again over the newer draft, **Take theirs** replaces the inputs with the draft's values.
  While you have unsaved edits no snapshot overwrites your inputs. `form.save` writes the shared
  draft (`FormCollabDraftStoreInterface::apply()`), not your record: see
  [Optimistic: the record is yours to save too](#optimistic-the-record-is-yours-to-save-too). A tab whose runtime sends no
  epoch is told to reload (`collab_client_outdated`).

## Saving with your own form post

The record is saved by your own POST, in every mode. The shell wraps the `content` slot in
`<form action="#" method="post" novalidate>`. In `Shared`, `FormLock` and `FieldLock` it adds no
button; in `Optimistic` its **Save** writes the draft only (see
[below](#optimistic-the-record-is-yours-to-save-too)). To keep your own submit beside it:

- **Name the target on the button:** `<button type="submit" formaction="/orders/42">` in the
  slot, as in the example above. The shell's form then posts to your route. (A control can
  instead point at a form of your own elsewhere on the page with the `form="…"` attribute; the
  runtime follows each managed input's own form.)
- **Give every input a `name`.** `data-ui-field-name` is the collaboration name; the browser
  posts by `name`.
- **Carry the CSRF token:** `{{ csrf_field() }}` in the slot ([CSRF](../auth/csrf.md)).
- **Submit through a real submit** (a button, `requestSubmit()`). A field someone else holds is
  `disabled`, and a browser leaves disabled controls out of the post. On the form's `submit`
  event the runtime enables the fields it disabled for as long as the browser reads the form,
  then disables them again. `form.submit()` and a `new FormData(form)` built outside a submit
  listener fire no such event, and the locked fields are missing from them. A control you
  disabled yourself is never touched.
- **End the draft after the save commits.** Until you do, the draft keeps the edits you just
  saved and writes them into the inputs of the next person who opens the form:

```php
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\PlatformUi\Application\Service\Collaboration\FormCollaborationDrafts;

#[InjectAsReadonly]
protected FormCollaborationDrafts $collabDrafts;

// in the handler of your POST, after the transaction committed:
$this->collabDrafts->discardForRecord('order', (string) $order->getId(), [
    'title' => $order->getTitle(),
    'notes' => $order->getNotes(),
]);
```

`discardForRecord()` takes the same `formKey` and `recordId` the page gave the shell. It deletes
the draft, whatever it holds by then, and tells the editors who have the form open:

- **with the stored values:** each managed field named in them is set to what was stored. A
  field someone is typing in keeps their text, which is sent on as a new edit. A field you do
  not name keeps what the tab showed, so pass every managed field.
- **without values:** open tabs keep what their inputs show.

Either way the component root dispatches `semitexa:collab:reset` (`detail.rebased` is `true`
when values were applied). Call it in the tenant whose record was saved: a queue worker or a
command outside that tenant ends nothing. The page the saver is redirected to is not told about
her own save.

The runtime also keeps the post and the live edits apart: when the page is hidden by your post,
the fields that post carried are not sent again as live edits, so no new draft starts behind
the save.

### An edit that arrives while you save

Edits keep reaching the draft while your handler runs. The discard does not know which of them
your save stored: it deletes the draft as it stands, and an edit that lands after the delete
starts a new draft. So an edit accepted between the moment your handler read the values it
stored and the discard is not in the record and no longer in a draft. What happens to it in the
open tabs depends on the values you pass:

- **a field you name:** every tab sets it to the stored value, and the edit is gone. One case is
  spared: a tab where the field has focus, or its edit is still on its way, keeps the text and
  sends it again as a new edit.
- **a field you do not name, or no values at all:** no input is changed. The edit stays in the
  inputs that show it, its author's and those of the tabs that received it, and reaches the
  record with the next post from one of them. A person who opens the form meanwhile sees the
  record without it.

Nothing in the package compares the two for you, and there is no discard that is refused when
the draft has moved on. What a handler can do is read the draft just before it discards,
`FormCollabDraftStoreInterface::load()` with the scope from `FormDocumentScope::forRecord()`,
and leave out of the stored values every field whose draft value differs from what it stored.
Those fields then keep what the tabs show, and `semitexa:collab:reset` still tells each page
that a save happened. An edit landing between that read and the discard is still treated as
saved. `FormLock` narrows the window to one writer; it does not close it.

A field left out survives in the open tabs only, not in the draft, which the discard deletes:
if those tabs close before one of them posts again, the edit is lost. An app that must not lose
an edit made during a save has to coordinate it itself, for example by persisting what the draft
held at the discard and reconciling it with the record afterwards; the package does neither.

### Optimistic: the record is yours to save too

The shell's **Save** in `Optimistic` writes the shared draft and nothing else. The handler
raises no event and calls no code of yours when a draft is saved, so no record is written by it.
What the code supports is the same own post as in the other modes:

- The shell wraps the slot in the same form in `Optimistic`, and its own button only answers its
  own click. A `formaction` button of yours in the slot posts the form to your route, with the
  rules above.
- That post carries what the tab's inputs hold, whether **Save** was pressed or not, and no
  draft version: the conflict prompt guards the draft, not your record. Two posts to your route
  are yours to order.
- Call `discardForRecord()` after the commit, as above. An open `Optimistic` tab with no
  unsaved edits is set to the stored values you pass. One with unsaved edits keeps them and
  meets the conflict prompt on its next **Save**, where **Take theirs** gives it the stored
  values.
- A handler that wants what **Save** committed rather than what one tab posted reads it from
  `FormCollabDraftStoreInterface::load()`: `values`, `version` and `epoch` of the draft.

## When a draft ends

A draft ends in two ways (`FormCollabDraftStoreInterface`):

- **`discard()`**, through `FormCollaborationDrafts` above.
- **Expiry.** A draft nobody has written to and nobody has been present in for
  `PLATFORM_UI_COLLAB_DRAFT_IDLE_MINUTES` (default 30; 0 turns expiry off) is dropped by the next
  read or write (`FormCollabDraftRetention`). When the cache cannot say whether anyone was
  present, the draft stays until it is a day old.

A form that sees its draft end without a save notice dispatches `semitexa:collab:reset` with
`detail.rebased` false. An `Optimistic` editor with unsaved edits meets the conflict prompt on
their next save.

## Hooks for your page

| Hook | What it is |
|---|---|
| `semitexa:collab:reset` | DOM event on the component root, bubbling: the draft ended. `detail.rebased` says whether stored values were applied. |
| `semitexa:collab:error` | DOM event on the component root, bubbling: the feed sent `ui.document.error`. `detail.error`, `detail.envelope`. |
| `semitexa:collab:expired` | DOM event on the component root, bubbling: the form's tokens expired and the server refused to renew them. Ask for a reload. |
| `[data-ui-collab-status]` | `data-state`: `connecting`, `live`, `offline`, `error` or `expired`. The runtime writes no text into it. |
| `[data-ui-collab-save-status]` | `Optimistic` only. `data-state`: `unsaved`, `saving`, `saved` or `error`, with a text. |
| `window.SemitexaUi.formCollab.bootAll(root)` | Scans `root` for collaborative forms. Markup added to the page later is found without it. |

## Limits today

- **`Optimistic` saves no record.** Its **Save** and version guard cover the shared draft only;
  nothing carries a saved draft into your record or tells your code about it. See
  [Optimistic: the record is yours to save too](#optimistic-the-record-is-yours-to-save-too).
- **A save and its discard are not one step.** An edit accepted in between is deleted with the
  draft. See [An edit that arrives while you save](#an-edit-that-arrives-while-you-save).

- **A page left open for a day.** The shell's tokens are signed for 300 s
  (`SignedContext::DEFAULT_TTL_SECONDS`). When HUG refuses one as expired, the runtime trades the
  manifest's renewal token for fresh ones and sends the refused edit, ping or lock request once
  more, so the form keeps saving. The renewal token lives a day
  (`CollabManifestBuilder::RENEW_TTL_SECONDS`) and is valid only for the session, tenant and
  signed-in person the page was drawn for. After a day, or once that person signed out or into
  another account, the renewal is refused: the status host gets `data-state="expired"`, the form
  dispatches `semitexa:collab:expired`, and nothing typed afterwards is shared until the page is
  loaded again.
- **Renewal follows your authorizer.** `manifest.renew` signs the form's tokens again only
  while your `UiInteractionAuthorizerInterface` still allows the edits they grant: it is asked
  about every event of the form's mode (`field.edit`, the lock events and the rest) as if each
  were being sent, with the form's scope in `$event->config['scope']`, and one denial refuses
  the renewal (403 `collab_renew_forbidden`). So access to a record that ends while a tab is
  open ends with the tokens the page holds, within five minutes. An authorizer that allows
  events by name must allow part `manifest`, event `renew` too, or no form outlives its first
  tokens. The renewal token itself lasts a day; after that the page must be reloaded.
- **Two tabs of one signed-in person are one participant:** one chip and one lock. A release
  from one tab ends the lock the other is using, and one tab skips the snapshots the other
  caused.
- **The shell's texts are English** ("Editing live ·", "Locked by", "Save", the conflict prompt).
- **Chips, the status host and a locked field are unstyled hooks:** style `.ui-collab-chip`,
  `[data-ui-collab-status][data-state]` and `[data-ui-collab-field-lock]` in your own CSS.

## Proof in the Playground

| What | Where | Checked by |
|---|---|---|
| all four modes, two participants on one page | `/ui-playground/components/collaborative-form?mode=…` | `collab-optimistic.spec.ts`, `collab-form-lock.spec.ts`, `collab-field-lock.spec.ts` |
| the runtime: edit order, locks, the form post, a draft's end | `semitexa/platform-ui` | `FormCollabRuntimeTest` |
| the server: policy, refusals, names | `semitexa/platform-ui` | `FormCollaborationEventHandlerTest`, `FormCollaboratorLabelTest` |
| a form open past its tokens' TTL keeps saving; who may renew | `semitexa/platform-ui` | `FormCollabContextRenewalTest` |

## Next steps

- [UI Events and Transport](ui-events.md#live-feeds): HUG, KISS and feeds, which carry all of this.
- [UI Forms](ui-forms.md): `platform.form`, for a form one person fills in and submits through HUG.
- [`#[CollaborativeForm]`](../reference/attributes-platformui.md#collaborativeform) in the generated reference.
