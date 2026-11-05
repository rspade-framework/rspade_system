<!-- single-source: never duplicate into another fragment. THIS FRAGMENT IS THE CANONICAL HOME of the UI philosophy; the z-index bands live in rsx:man zindex. -->

## UI & STYLING

**Spinners come from ONE registry - never hand-roll spinner markup, never pass a size**: `<Spinner />` or `$(el).component(Rsx.get_default_spinner())` in a host sized with CSS; an app registers its own with `Rsx.set_default_spinner()` from a static `on_app_modules_define()`. A page or dialog hidden until ready is `@spinner_until_ready('uncached'|'always')`, never hand-rolled. Skill `rspade:spinners`.

**Asset split**: jqhtml = markup, JS = logic and state, SCSS = component styles, auto-bundled. **No `<style>`/`<script>` tags, no inline event handlers, and no hand-injected external script or stylesheet** - declare it in a `*.externals.php` and `await Rsx.load_external('id')`; the CSP derives from that declaration, so an ad-hoc external is a blocked external (skill `rspade:external-resources`).

**Every styled element is a component whose SCSS wraps in one class matching it** - copy-pasted markup means extract a component; page/action SCSS stays near-empty, `rsx/lib/` is non-visual. **BEM children use the exact PascalCase component name** (`.Component_Name { &__element }`) - kebab-case silently gets no styles. Check `rsx/theme/variables.scss` before writing SCSS; text DATA (a log, code, a preview) uses the import-free mixins `rsx-text-mono` / `rsx-text-sans` / `rsx-preview-text-pane`. **Serious business tools**: hover effects ONLY on interactive elements, no animation on non-actionable ones, filled buttons (`btn-primary`) except icon-only button groups.

**Bootstrap's breakpoint classes do NOT work** (`.col-md-6`, `.d-lg-none`): the tiers are `mobile`/`desktop`, then `phone`/`tablet`/`desktop-md`/..., as mixins (`@include mobile { }`), infixed classes for non-zero tiers (`.col-tablet-6`) and `Responsive.is_mobile()`.

Skill `rspade:scss-rules`, app skill `theme`. `rsx:man scss`, `rsx:man responsive`, `rsx:man zindex`.
