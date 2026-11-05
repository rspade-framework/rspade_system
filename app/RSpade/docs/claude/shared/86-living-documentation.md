<!-- single-source: never duplicate into another fragment. Canonical statement of the rsx/ living-documentation rule; true in the monorepo (rsx/ is the reference app) and downstream (rsx/ is the developer's fork). Divergence sections are downstream-only: app/86. -->

## LIVING DOCUMENTATION IN rsx/

**Everything under `rsx/` is application code, and every `CLAUDE.md` and skill under it is LIVING documentation of its directory's CURRENT state** - what is there, how it is used and customized - never a record of what shipped. **When a change alters the behaviour, contents or conventions of a directory that carries a `CLAUDE.md`, updating that file is PART OF THE CHANGE**, in the same pass, unasked; a directory without one is undocumented, never "documented elsewhere". Currency is audited (conformance audit `living_documentation_currency`), not linted; `rsx:man template_app`, LIVING DOCUMENTATION.
