# Build With Semitexa

This section is about one thing: how to build real applications in the Semitexa way.

Not the accidental way.
Not the "there are six acceptable patterns" way.
Not the "you can do anything" way that eventually means nobody knows what the codebase is supposed to look like.

Build is where Semitexa becomes practical.

## Start Here

- [Get Started](GET_STARTED.md)  
  Install and run the app.

- [A Minimal Working Page](MINIMAL_PAGE.md)  
  The fastest way to understand the Semitexa mental model.

- [AI Best Practices](AI_BEST_PRACTICES.md)  
  Practical patterns, structure, and anti-patterns.

## What Build Means In Semitexa

Semitexa tries to remove avoidable architectural branching.

That means:

- requests enter through payloads
- behavior lives in handlers
- output lives in resources and templates
- routes live in modules
- structure is visible from the filesystem

The framework is opinionated because ambiguity is expensive.

## Build Principles

- **Payload first**: request contract and validation live in the payload.
- **Modules only**: routes and application features live in modules.
- **One clear structure**: folder layout should be predictable.
- **Long-lived runtime discipline**: Swoole changes what "safe code" means.

## What You Should Feel Here

When Semitexa is working well, building should feel:

- more explicit
- more constrained
- easier to reason about
- less magical

The constraints are there to lower cognitive load later.

## Next Step Into Reference

When the basic mental model is clear, continue with [REFERENCE.md](REFERENCE.md) for deeper technical detail.
