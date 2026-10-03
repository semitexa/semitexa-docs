# Git Flow in the Semitexa Workspace

How work moves through the framework's own repositories: every `packages/semitexa-*` package is its own repository, and each follows the same flow. This is a rule for contributors to the framework. A project built on Semitexa keeps whatever flow it chooses.

## The rules

- **Work on `develop`.** Commit local work directly to `develop`.
- **No feature branches.** Do not create a branch off `develop`, and never open a pull request from a temporary branch (`codex/*`, `claude/*` or any other).
- **One kind of pull request: `develop` → `master`.** Use it unless the operator explicitly says otherwise. Review happens there.
- **Push before the pull request.** Make sure the commits are on local `develop` and pushed to `origin/develop` before the pull request is created or updated.

## What enforces them

- `review-prep` never uses `master` or `main` as a pull-request head, and opens or updates the `develop` → `master` pull request for the current repository.
- `codereview` merges with a merge commit and never deletes `develop`.
- Releases are cut from tags on `master` (`release-readiness`), so nothing reaches a release without passing through that pull request.

## Why

One long-lived branch per repository, across more than forty repositories, keeps every package's state in one place: what is merged is on `master`, and what is in review is the difference between `develop` and `master`. Several agents work in one checkout at the same time; a feature branch per change would hide work from the others and from the review and release tooling, which look at `develop` and `master` only.
