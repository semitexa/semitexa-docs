---
id: rendering/ui-behaviors
section: rendering
slug: ui-behaviors
title: UI Behaviors
summary: Client-only interactions bound by markup — ui-behavior="menu|dropdown|modal|…" — on the native platform (popover, commandfor, CSS anchor positioning), with their keyboard and ARIA wired by the runtime.
order: 165
locale: en
status: canonical
verified_against: 2026.10.03.1952
keywords:
  - AsUiBehavior
  - ui-behavior
  - menu
  - dropdown
  - popover
---

# UI Behaviors

A behavior is an interaction that needs no server: open a menu, trap focus in a modal, switch a tab. The server marks an element with `ui-behavior="<alias>"`; the runtime (`platform-ui/behaviors`) connects it, wires roles and keyboard, and tears it down when the element leaves the page. Options use a small DSL on `ui-<alias>="key: value; key: value"`, typed by the behavior's declaration (`#[AsUiBehavior]`).

Built in: `menu`, `dropdown`, `modal`, `offcanvas`, `tabs`, `accordion`, `toggle`, `tooltip`, `toast`, `sticky`, `scrollspy`, `removable`, `skin-mode`. `bin/semitexa platform-ui:catalog --kind=behavior` lists them with options and declared a11y capabilities.

## Menus — native platform first

```twig
<div ui-behavior="menu">
  <button ui="button" ui-behavior-toggle>Actions</button>
  <div ui-behavior-content>
    <button ui-behavior-item>Edit</button>
    <button ui-behavior-item role="menuitemcheckbox" aria-checked="false">Pinned</button>
    <div role="group" aria-label="Sort by">
      <button ui-behavior-item role="menuitemradio" aria-checked="true" data-value="new">Newest</button>
      <button ui-behavior-item role="menuitemradio" aria-checked="false" data-value="old">Oldest</button>
    </div>
    <hr>
    <div ui-behavior="menu">                      {# a submenu #}
      <button ui-behavior-item ui-behavior-toggle>Export as</button>
      <div ui-behavior-content> … </div>
    </div>
  </div>
</div>
```

- **The panel is a `popover`.** It lives in the top layer, so there are no z-index fights. Light dismiss (an outside click, or Esc) closes it, and a submenu stays open as a child of its parent.
- **The trigger invokes it** with `commandfor` / `command="toggle-popover"` where buttons support that, and a click handler does the same elsewhere.
- **CSS anchor positioning** places the panel, flipping when it would clip, with a computed fallback. Browsers without `popover` fall back to `hidden` + an outside-click dismiss.
- **Keyboard (WAI-ARIA menu button):**
  - ArrowDown / ArrowUp on the trigger opens the menu on the first / last item;
  - arrows, Home and End move within it, and typing a letter jumps;
  - ArrowRight opens a submenu and ArrowLeft returns to its item;
  - Esc closes it with focus back on the trigger, and Tab closes it and moves on (a menu is not a focus trap).
- **Choosing an item** emits `sx:menu:select` with `{value, checked, item}`. A plain item closes the whole menu. A `menuitemcheckbox` toggles `aria-checked` and keeps it open. A `menuitemradio` checks itself within its `role="group"`.
- **An item can be a server action.** Give it `data-ui-part="archive"` inside a component and it reaches that component's `#[UiOn(part: 'archive', event: 'click')]` through HUG.
- `ui-menu="label"` and `ui-menu="shortcut"` style a group heading and a trailing shortcut (for example `primitive('kbd', {keys: ['Ctrl', 'B']})`).

`dropdown` runs on the same base: the same `usePopoverPanel` + `useMenuKeys` composables, the same markup (`ui-behavior-toggle`, `ui-behavior-content`, `ui-behavior-item`). A panel without items keeps its own semantics, for example a form in a dropdown. `ui-dropdown="mode: hover"` opens it on hover.

## Modal in the address

`ui-modal="urlParam: edit"` makes a dialog part of the page's address:

- it opens on load when the address has `?edit`;
- opening it adds the parameter, and closing it removes the parameter (the history entry is rewritten, not pushed);
- back and forward open or close it to match.

A trigger can then be a real link, so the dialog can be bookmarked, opened in a new tab, or reached without JavaScript:

```twig
<a ui="button" href="?create" ui-behavior-open="#create">New product</a>
<dialog id="create" ui-behavior="modal" ui-modal="urlParam: create"> … </dialog>
```

[CRUD screens](crud-screens.md) open their create and edit dialogs this way.

## Removable

`ui-behavior="removable"` with a `[ui-removable-trigger]` inside: a click dispatches a cancelable `ui-removable:remove` event. Unless a listener prevents it, the element is removed along with any hidden form value it carried, and focus moves to the next removable sibling. The removable `tag` primitive uses it.

## Composables

Behavior modules import the composables from `platform-ui/behaviors`:

- `usePopoverPanel(trigger, panel, {pos, offset, flip, onOpen, onClose})` — the menu and dropdown base;
- `useMenuKeys(panel, {items, onTab})` — the menu keyboard;
- `useFloating`, `useTogglable`, `useFocusTrap`, `useDismiss`, `useScrollLock`, `useTransition`, `useInView`.

Register your own with `registerBehavior({name, ui, options, connect(el, opts, ctx)})` and declare it in PHP with `#[AsUiBehavior]`.

## Skin mode

`ui-behavior="skin-mode"` binds the radios inside it — values `light`, `dark` and `auto` — to the unified skin-mode contract:

- `<html data-skin-mode>`;
- `localStorage.semitexa_skin_mode`, absent when following the system.

This is the same contract the theme's pre-paint script reads. `auto` follows `prefers-color-scheme` live. `platform.appearance-settings` is the ready-made control.

