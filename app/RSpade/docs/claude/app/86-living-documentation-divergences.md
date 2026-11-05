<!-- bucket: app - single-source, never duplicate. True ONLY in a downstream application; the living-documentation rule itself is shared/86. -->

## DIVERGENCES FROM FRAMEWORK DOCS

**A living file in `rsx/` mentions how something USED to work in one case only**: an immutable framework tier (a `system/` skill, man page or fragment that `rsx:framework:pull` keeps restating) still states the old behaviour. Then its final `## DIVERGENCES FROM FRAMEWORK DOCS` section - created the first time it is needed, never pre-emptively - carries one short paragraph per divergence: which framework document says what, and what this application does instead. No changelog, no dates, no "previously": the framework text is the before, this file is the after. **A framework page whose subject lives in `rsx/` opens with a TEMPLATE FEATURE notice naming the living file - and where that file records a divergence, it wins.**
