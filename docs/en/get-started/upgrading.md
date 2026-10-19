---
id: get-started/upgrading
section: get-started
slug: upgrading
title: Upgrading
summary: Move a project to the newest Semitexa release with one command, see what it will do first, and know what to do with the .new files it may leave next to your own.
order: 80
locale: en
status: canonical
verified_against: 2026.10.19.0811
keywords:
  - update
  - update --dry-run
  - update:history
  - update:changelog
  - .new
  - server:restart
  - self-test
---
# Upgrading

A Semitexa project upgrades with one command, run from the project directory while the stack is up:

```bash
bin/semitexa update --dry-run
bin/semitexa update
bin/semitexa server:restart
```

## See it first

`update --dry-run` changes nothing. It prints:

- every `semitexa/*` package that will move, from the version you have to the one the newest release pins;
- what happens to each scaffold file (`bin/semitexa`, the `docker-compose*.yml` files, `.env.default`, the agent docs such as `AGENTS.md` and `CLAUDE.md`, the phpstan configs): already current, replaced, or left alone because you changed it;
- the database schema changes and data patches that will run.

## What `update` does

In this order, and it stops at the first stage that fails:

1. **Preflight.** Read-only checks before anything is touched: the database answers, there is disk space, `composer.json` and `composer.lock` are readable, and `var/` is writable. A failed check stops the update with nothing changed.
2. **Packages.** Every `semitexa/*` package moves to the version the newest `semitexa/ultimate` release pins — the set that release was tested as. Composer runs inside the containers; if it fails, `composer.json`, `composer.lock` and `vendor/` are put back as they were.
3. **Itself first.** When the release brings a newer `semitexa/update`, the command stops after the package step and runs again on the new code, so the remaining stages are the new release's. You see this as a note in the output; it is not an error.
4. **Scaffold files.** A file you never changed is replaced with the new release's version. A file you did change is **left as it is**, and the new version is written next to it as `<file>.new`.
5. **Database.** Data patches and the schema sync for the new release, the same as `orm:sync`. A destructive schema change (a dropped column, a narrowed type) is refused unless you pass `--allow-destructive`.
6. **Health check.** When `SEMITEXA_UPDATE_HEALTHCHECK_URL` is set, the update counts as successful only if that URL answers. Without it this stage is skipped.
7. **Journal.** The run is recorded: `bin/semitexa update:history` lists past runs and their outcome.

Then `server:restart`, so the workers load the new code.

## The `.new` files

A `.new` file means: this release changed a file you had changed too. Compare the two and carry over what you need — for example:

```bash
diff CLAUDE.md CLAUDE.md.new
```

Keep your file, take the new one (`mv CLAUDE.md.new CLAUDE.md`), or merge them by hand, then delete the `.new`. Nothing reads a `.new` file; it is only there for you.

Some files are only reported, not written as `.new`, when you changed them (the dry run says `manual_review`). `bin/semitexa update --write-scaffold-candidates` writes their `.new` files too.

## Check the result

```bash
bin/semitexa self-test
bin/semitexa update:changelog
```

- `self-test` should report no errors.
- `update:changelog` shows which package versions changed.
- Open the app: your own modules and pages are untouched by the upgrade; only `semitexa/*` packages and the scaffold files above are.

## Why this matters

An upgrade a project cannot see coming, or that writes over the files it changed, is one that gets put off. One command that shows its plan, leaves your edits in place and records what it did keeps a project on the current release.
