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

## Positioning Rule

Every public page should help the reader feel one of these things:

- "I understand the problem."
- "I can start quickly."
- "I see the system."
- "I know the right way to do this."

If a page does not clearly serve one of those goals, it should be shortened, merged, or removed.

## Content Rules

- Keep one canonical page per topic.
- Use `docs/ai/` and `docs/hm/` only as audience-specific entry points.
- Put philosophy in `README.md` and `AI_REFERENCE.md`.
- Put practical how-to content in `docs/*.md`.
- Put deep package internals in package docs such as `vendor/semitexa/core/docs/`.

## Navigation Rule

The public site should make the main path obvious:

`Why -> Get Started -> Build -> Reference -> AI`
