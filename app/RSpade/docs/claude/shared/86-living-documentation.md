<!-- single-source: never duplicate into another fragment. THIS FRAGMENT IS THE CANONICAL
STATEMENT of the rsx/ living-documentation rule. BUCKET shared/: true in the monorepo (rsx/
is the reference app we author) and downstream (rsx/ is the developer's fork). -->

## LIVING DOCUMENTATION IN rsx/

**Everything under `rsx/` is application code and is meant to be modified.** Every `CLAUDE.md`
under `rsx/` is a **living description of the current state of its directory** - what is
there, how it is used, how it is customized - never a record of what shipped. **When an
implemented change alters the behaviour, contents or conventions of a directory that carries
a `CLAUDE.md`, updating that file is PART OF THE CHANGE**, done in the same pass without being
asked; a directory with no `CLAUDE.md` is undocumented, never "documented elsewhere". A skill under
`rsx/resource/skills/` is living documentation under exactly the same rule.

A living file describes present behaviour only. It refers to how something *used to* work in
exactly one case: when the previous behaviour is still stated by an immutable framework tier
(a `system/` skill, man page or always-on fragment that `rsx:framework:pull` keeps restating).
Then add a final section headed **`## DIVERGENCES FROM FRAMEWORK DOCS`** - created the first
time it is needed, never pre-emptively - with one short paragraph per divergence: which
framework document says what, and what this application does instead. No changelog, no
dates, no "previously": the framework text is the before, this file is the after.

Framework skills, man pages and fragments whose subject lives in `rsx/` open with a
**template-feature notice** naming the living file; **where the living file records a
divergence, it wins.** Currency is audited before launch (`rsx:man prelaunch_checklist`,
the LIVING DOCUMENTATION entry), not linted.
