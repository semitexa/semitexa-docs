# About Semitexa

> 🤖 **AI Agent Note:** For a hallucination-free development experience,
> follow the [AI-Optimized Guide](./AI_REFERENCE.md).

**Start here:** [Get Started](docs/GET_STARTED.md) · [Build With Semitexa](docs/BUILD.md) · [AI Best Practices](docs/AI_BEST_PRACTICES.md)  
**Docs architecture:** [Docs Map](docs/README.md) · [Site Map](docs/SITE_MAP.md) · [Reference](docs/REFERENCE.md) · [AI](docs/AI.md)

---

### Install Locally

The official local install flow is now one command:

```bash
curl -fsSL https://semitexa.com/install.sh | bash
```

Or choose a project directory explicitly:

```bash
curl -fsSL https://semitexa.com/install.sh | bash -s my-project
```

Host prerequisites are intentionally minimal:

- Composer
- Docker Compose

You do not need PHP installed on the host just to get a local Semitexa project running.

---

### Finally, A Framework With A Shape

Semitexa exists for developers who are tired of paying for architecture twice:

- once in runtime complexity,
- and again in mental complexity.

Modern PHP can do serious work. The problem is that the path there often becomes too expensive, too noisy, and too hard to reason about. The code runs, but the system stops feeling clean. Every new feature adds another hidden rule, another convention, another "special case" the team has to remember forever.

Semitexa is a direct response to that drift.

The promise is simple:

- fewer hidden rules
- fewer accidental patterns
- fewer places where humans and AI have to guess
- one clearer way to build

It is not trying to be clever. It is trying to be legible.

---

### The Economics of Simplicity

PHP once won because it let teams build real things quickly without requiring a huge machine around them. That advantage eroded. Projects became heavier. "Clean architecture" often turned into ceremony. Teams started spending more money understanding the system than extending it.

Semitexa is built to reclaim that lost advantage:

- keep professional structure
- reduce architectural drag
- make change cheaper
- make reasoning faster

The goal is not minimalism for its own sake. The goal is to make serious software feel buildable again.

### Beyond the "Born to Die" Paradigm

Classic PHP assumes the application is born and dies on every request. That model was stable, but it also trained the ecosystem around a set of assumptions that start to crack under real concurrency, real state, and real performance demands.

Semitexa is built on **Swoole** because long-lived runtime changes the game:

- state must be disciplined
- request boundaries must be explicit
- memory safety stops being optional
- the framework must help you reason correctly

This is not "PHP, but faster." It is PHP with a runtime model that forces architectural honesty.

### Architectural Logic and "The Elegance Paradox"

In many large systems, good architecture becomes politically expensive. You say "we need structure," the business hears "we need more time." You say "we need clarity," they hear "we need more abstraction." Clean architecture gets blamed for costs that usually come from inconsistency, sprawl, and unclear rules.

Semitexa tries to break that pattern.

The design principle is:

- clean should be easier to explain
- structure should reduce cost, not increase it
- the right path should also be the easier path

That is the real target of the framework: not abstract elegance, but affordable clarity.

### AI-Native Engineering: A New Board, New Rules

AI changed the rules of software engineering. A framework that hides behavior in magic, loose convention, or undocumented corners is now expensive in a new way: both humans and models make more mistakes inside it.

Semitexa is designed to be **AI-oriented from the ground up**:

- explicit types
- explicit contracts
- explicit module structure
- predictable discovery
- fewer parallel ways to do the same thing

The aim is not to replace developers with AI. The aim is to make the codebase understandable enough that both humans and AI can work in it without guessing.

### Heritage and Open Source

The Semitexa core is built upon the collective wisdom of the PHP community. It reflects lessons learned from decades of framework and platform evolution across the ecosystem.

Semitexa does not seek to replace existing tools but to offer a specialized alternative for those facing new-generation challenges. At its heart, Semitexa is a commitment to the Open Source ecosystem and the continuous evolution of professional PHP.

---

### Documentation Conventions

Semitexa documentation is part of the product. It is not an afterthought and not a dump of internal notes.

Public docs are written to answer five questions:

- Why does this exist?
- How do I start?
- What is the Semitexa way?
- What should I never do?
- Where do I go next?

Technical documentation across Semitexa packages follows a standardized structure to keep those answers clear for both human developers and AI agents:

* **Purpose**: Clear definition of the document's or feature's objective.
* **Scope / Use Case**: Specific scenarios where the feature should be applied.
* **Rules & Constraints**: Strict guidelines on implementation (and anti-patterns to avoid).
* **Mapping**: Explicit references to files, classes, or CLI commands.

Whenever applicable, documentation includes a **Rationale** section. By making the "Why" behind design choices explicit, Semitexa ensures that the codebase remains maintainable, predictable, and logical for long-term collaboration.

---

### Single Source Documentation

This package uses one canonical guide per topic.

- `docs/*.md` are the source of truth.
- `docs/ai/` and `docs/hm/` are lightweight entry points, not parallel full copies.
- If a topic changes, update the canonical guide first.

---

### Recommended Reading Order

1. [Get Started](docs/GET_STARTED.md)
2. [A Minimal Working Page](docs/MINIMAL_PAGE.md)
3. [Build With Semitexa](docs/BUILD.md)
4. [AI Best Practices](docs/AI_BEST_PRACTICES.md)
5. [Reference](docs/REFERENCE.md)

---

### What You Should Feel

If Semitexa is doing its job well, the reaction should be:

- "I understand the rules."
- "I know where things go."
- "I do not have to guess."
- "This is stricter, but simpler."
- "Finally, someone chose one clear way."
