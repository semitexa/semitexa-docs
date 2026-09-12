# Semitexa Docs — Site Map

This package is the content source for the public Semitexa documentation site.

The docs are organized around a simple journey:

1. **Why Semitexa**  
   The problem, the philosophy, and the promise.  
   Entry: [../README.md](../README.md)

2. **Get Started**  
   The shortest path from zero to a running app.  
   Entry: [GET_STARTED.md](GET_STARTED.md)

3. **Build**  
   How to actually create pages, modules, handlers, and applications in the Semitexa way.  
   Entry: [BUILD.md](BUILD.md)

4. **Reference**  
   Precise package-level technical documentation.  
   Entry: [REFERENCE.md](REFERENCE.md)

5. **AI**  
   Philosophy for agents plus practical implementation rules.  
   Entry: [AI.md](AI.md)

## Where a page goes

The five slots above are the architecture. Which slot a page belongs to, what
shape it takes, and how generated reference fits alongside hand-written guides
are settled in
[workspace/INFORMATION_ARCHITECTURE.md](workspace/INFORMATION_ARCHITECTURE.md).

## Positioning Rule

Every public page should help the reader feel one of these things:

- "I understand the problem."
- "I can start quickly."
- "I see the system."
- "I know the right way to do this."

If a page does not clearly serve one of those goals, it should be shortened, merged, or removed.

## Content Rules

- Keep one canonical page per topic.
- Keep framework-wide and package-specific documentation in `semitexa/docs`.
- Use `README.md` for the framework's purpose and philosophy, and put practical
  guides, explanations, and reference material under `docs/`.
- A package may keep only its `README.md`, `CHANGELOG.md`, and
  `docs/MODULE_STRUCTURE.md`. Its README links to the canonical page here
  instead of duplicating user-facing documentation.
- Keep generated reference pages beside the hand-written guides they support;
  generated output is evidence, not a second documentation hierarchy.

## Navigation Rule

The public site should make the main path obvious:

`Why -> Get Started -> Build -> Reference -> AI`
