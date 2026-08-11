# Semitexa Reference

This section points to the precise technical reference for Semitexa packages.

Reference is where ambiguity should disappear.

If `Get Started` gives you momentum and `Build` gives you the mental model, `Reference` should give you exactness.

## The generated reference

[en/reference/](en/reference/README.md) — every attribute, command and event the
framework exposes, produced from the code by `bin/semitexa docs:reference:generate`.
Each attribute carries the signature the class has and a usage quoted from the
codebase, with the file it came from.

Those pages are generated. If one reads badly the fix belongs in the generator
or in the code, not in the page.

The package paths this section used to list are gone: package documentation now
lives in this hub, by subject. See
[workspace/DOCUMENTATION_OWNERSHIP.md](workspace/DOCUMENTATION_OWNERSHIP.md).

## What Reference Is For

Use reference when you need:

- exact attribute behavior
- exact folder and module rules
- exact runtime or Docker expectations
- exact DI and contract mechanics

Reference should answer "what is correct?" not "what is inspiring?"

## Use Reference For

- exact folder and attribute rules
- DI and service contract behavior
- payload validation details
- runtime and Docker behavior
- package-level implementation specifics

## When Not To Start Here

If you are new to Semitexa, start with:

- [Get Started](GET_STARTED.md)
- [A Minimal Working Page](MINIMAL_PAGE.md)
- [Build With Semitexa](BUILD.md)

If you start with reference too early, Semitexa can look stricter than it feels in practice. The right order is understanding first, precision second.
