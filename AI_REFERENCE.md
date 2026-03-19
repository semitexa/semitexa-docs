# Semitexa: Philosophy for AI Agents

> 👤 **For humans:** This is the philosophy document adapted for LLMs. The same ideas, in human language and with more context, live in the [About Semitexa](./README.md) manifesto.

This document states **why** Semitexa exists and **what problems** it tries to solve. Technical reference (lifecycle, modules, commands, APIs) lives in project and package documentation; here we only state the spirit and the pain that shaped the design.

---

## The Economics of Simplicity

**Pain:** PHP and LAMP once won by being affordable. Today, PHP projects have become very expensive to build and maintain.

**Goal:** Make development economically viable again. Semitexa is built with cost and simplicity in mind: fewer moving parts, predictable structure, so that building and changing things stays affordable.

**For agents:** When you suggest design or dependencies, prefer simplicity and explicitness. Avoid solutions that increase long-term cost or opacity; the project explicitly values affordability.

---

## Beyond "Born to Die"

**Pain:** Classic PHP is "born to die": each request boots the app and then the process ends. That model has limits for scaling and for keeping state (connections, caches, workers). Existing big frameworks often assume this model and become heavy and inert.

**Goal:** Semitexa is built on **Swoole**: long-lived process, request loop, persistent memory. Scaling and statefulness are part of the core design, not bolted on. The framework is meant to stay nimble as projects grow.

**For agents:** Assume a long-lived runtime. Do not rely on "fresh process per request." Request-scoped state exists explicitly (e.g. session, cookies, current request); anything that must not leak between requests is isolated by design. Technical details are in package docs (e.g. sessions and cookies, request-scoped container).

---

## The Elegance Paradox

**Pain:** In complex systems (e.g. Magento), "clean architecture" is expensive. When you justify cost with "architecture," business often hears "bad architecture." So good design becomes hard to defend.

**Goal:** Semitexa aims to bridge that gap: structure and clarity without the usual cost in explainability. The architecture should be discoverable and understandable — so that both humans and automations can reason about it without magic. Clean is not meant to mean "expensive and opaque."

**For agents:** Prefer clear, explicit structure over clever indirection. Prefer composition and explicit contracts. The codebase is intended to be navigable and predictable; when you generate or refactor code, preserve that property.

---

## AI Agents: A New Game

**Pain:** LLMs often hallucinate when working with frameworks that rely on convention, magic, or implicit behavior. The "rules" are buried in docs or in runtime, and models guess wrong.

**Goal:** Semitexa is designed to be **AI-oriented**: explicit types, explicit contracts, predictable naming and structure, so that an agent can infer behavior without guessing. The aim is not to fight AI but to make the codebase a reliable target for AI-assisted development.

**For agents:** You are a first-class user of this stack. When the docs say "do X" or "avoid Y," it is so that both humans and agents can rely on the same behavior. Prefer strict types, explicit interfaces, and attribute-driven discovery over convention and magic. When in doubt, the project favors clarity and predictability over brevity.

---

## Summary for LLMs

- **Affordability and simplicity** are explicit goals; avoid designs that increase cost or opacity.
- **Long-lived runtime (Swoole)** is core; request-scoped state is explicit; do not assume a new process per request.
- **Explainable, discoverable architecture** is preferred; clean should not mean opaque or unjustifiably expensive.
- **AI-oriented** means the codebase is built so that agents can reason about it reliably; follow the documented patterns and prefer explicit, typed, contract-based design.

Technical details — request lifecycle, modules, registry, commands, sessions, attributes — are documented in the project and in the `semitexa-core` (and other) package docs. Those docs use a simple structure (Purpose, When to use, Rules/Steps, Where it lives) and often include a short **Why** or **Rationale** for key decisions. This file is the **philosophy**: the pain and the intent behind Semitexa, in a form you can use to align your suggestions and generated code with the project’s goals.

**Canonical guides:** [Get Started](./docs/GET_STARTED.md) · [A minimal working page](./docs/MINIMAL_PAGE.md) · [AI Best Practices](./docs/AI_BEST_PRACTICES.md).  
**Agent entry points:** [docs/ai/](./docs/ai/).
