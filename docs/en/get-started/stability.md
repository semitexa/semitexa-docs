---
id: get-started/stability
section: get-started
slug: stability
title: Versions and stability
summary: What a Semitexa version number tells you, which code is promised to keep working across releases, and how long a deprecated part keeps working before it is removed.
order: 90
locale: en
status: canonical
verified_against: 2026.10.29.1052
keywords:
  - version
  - stability
  - "@api"
  - "@deprecated"
  - "@internal"
  - deprecation
  - backward compatibility
  - 90 days
---
# Versions and stability

After this page you can tell which parts of Semitexa your code may rely on from one release to the next, and how much warning you get before one of them goes away.

Prerequisites: none. To try the commands, a project installed as in [Installation](installation.md).

## What a version number says

Every release is tagged with the UTC date and time it was cut: `2026.10.29.1052` is 29 October 2026, 10:52 UTC. Tags are placed on `master`. A release tags each package that changed since its last tag; a package that did not change keeps the version it had.

The number is a date, not a compatibility signal. A later date is a newer release; nothing in the digits says whether it breaks anything. What it does say is **when**, and the promise below is made in time: a deprecated part keeps working for a stated number of days, counted from dates you can read straight off the version.

`semitexa/ultimate` pins every `semitexa/*` package to the exact version it was tested with, so a project moves the whole framework at once:

```bash
bin/semitexa update --dry-run
bin/semitexa update:changelog
```

The first shows which packages move and to which version; the second, after an update, what changed.

## What is promised: `@api`

The promise covers what is marked `@api` in its docblock, and nothing else. Marking is deliberate and opt-in: a class, interface, trait, enum or method is public API when the framework has said so, not because PHP lets you call it.

For example:

```php
/**
 * @api
 */
interface Clock
{
    public function now(): \DateTimeImmutable;
}
```

For a symbol marked `@api`, a release does not, without the deprecation below:

- remove it, or rename it;
- remove a public method, a constant, an enum case, or a parameter;
- add a required parameter, or change a parameter's or a return value's type to one your existing code no longer fits;
- add a method to an interface or an abstract method to a class you are meant to implement or extend.

A class marked `@api` makes that promise for its public methods; a single method can also be marked on a class that is not.

What these are is said in the code, so you can list them in your own `vendor/`:

```bash
grep -rln '@api' vendor/semitexa/*/src
```

Packages are being marked one at a time, starting with `semitexa/core`: the contracts you implement, the attributes you write on your own classes, and the base classes you extend. A package in which that command finds nothing has made no promise yet.

## What is not promised

Everything not marked `@api` can change in any release: unmarked classes and methods, anything marked `@internal`, the shape of generated files and caches, and the text of console output and log messages. Code that relies on them works today and may need a change after an update; `update --dry-run` and `update:changelog` are where you see that coming.

## Deprecation: 90 days

When a part of the `@api` surface has to change, the old form is deprecated first and keeps working:

```php
/**
 * @api
 * @deprecated 2026.11.02.0900 Use Clock::now() instead.
 */
public function currentTime(): \DateTimeImmutable
```

- `@deprecated` names the release that first shipped the deprecation and what to use instead.
- The deprecated part keeps working, unchanged, for **at least 90 days** after that release's date.
- It may be removed in the first release cut after those 90 days, not before. With the example above, that is a release on or after 31 January 2027.

So a project that updates at least once a quarter always sees a deprecation, and its replacement, before the removal reaches it.

## Fixes for older releases

Only the latest release is supported. There are no maintenance branches and no long-term-support line: a fix, including a security fix, ships in the next release, and updating is how you get it. The 90-day window above is what makes updating often safe for code that stays on the `@api` surface. How to report a vulnerability is in each package's `SECURITY.md`.

## Next steps

- [Upgrading](upgrading.md) — what `bin/semitexa update` does, step by step
- [Installation](installation.md) — a project to try the commands in
