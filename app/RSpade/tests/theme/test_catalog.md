# Test Catalog: theme

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| THEME-RESOLVE-DARK | an explicit dark preference resolves on the server | php | mode=1 | get_mode 1, is_dark true | implemented | 2026-08-27 |
| THEME-RESOLVE-LIGHT | an explicit light preference resolves on the server | php | mode=0 | is_dark false | implemented | 2026-08-27 |
| THEME-AUTO-NULL | auto resolves to NULL, never to a guess | php | mode=2 | is_dark null | implemented | 2026-08-27 |
| THEME-CLASSES | mode class always present; dark class only when dark is active | php | mode=1 then 0 | rsx-theme-*, rsx-dark only for dark | implemented | 2026-08-27 |
| THEME-AUTO-CLASSES | auto states the mode but asserts no theme | php | mode=2 | rsx-theme-auto, no rsx-dark | implemented | 2026-08-27 |
| THEME-ATTRS | app-declared attributes render for the active theme | php | declared dark/light sets | the active set | implemented | 2026-08-27 |
| THEME-ATTRS-AUTO | auto renders no attributes (client resolves) | php | mode=2 + declared sets | empty | implemented | 2026-08-27 |
| THEME-ATTRS-NONE | an app declaring no vocabulary is valid | php | empty sets | empty attrs, class still lands | implemented | 2026-08-27 |
| THEME-CHANGED | set_mode reports whether the resolved answer moved | php | light->dark, dark->dark | true, false | implemented | 2026-08-27 |
| THEME-INVALID | an unknown mode is refused | php | set_mode(99) | InvalidArgumentException | implemented | 2026-08-27 |
| THEME-NORMALIZE | null / non-numeric read as AUTO, not as light | php | 42, null, '1' | auto, auto, dark | implemented | 2026-08-27 |
| THEME-OPTIONS | the widget options come from the model enum | php | mode_options() | 3, light first | implemented | 2026-08-27 |
| THEME-BODY-SSR | the body tag carries the theme in the SERVER HTML | playwright | explicit dark | rsx-dark present pre-JS | planned | 2026-08-27 |
| THEME-OS-FOLLOW | auto follows a live OS scheme change without reload | playwright | emulated scheme change | class flips | planned | 2026-08-27 |
| THEME-SPA-DISABLE | a saved change arms a full page load for the next navigation | playwright | save then navigate | full load, new theme | planned | 2026-08-27 |
