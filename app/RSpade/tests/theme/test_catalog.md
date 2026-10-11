# Test Catalog: theme

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| THEME-RESOLVE-DARK | an explicit dark preference resolves on the server | php | mode=1 | get_mode 1, is_dark true | implemented | 2026-08-27 |
| THEME-RESOLVE-LIGHT | an explicit light preference resolves on the server | php | mode=0 | is_dark false | implemented | 2026-08-27 |
| THEME-AUTO-NULL | auto resolves to NULL, never to a guess | php | mode=2 | is_dark null | implemented | 2026-08-27 |
| THEME-CLASSES | the mode class names the choice, the dark class what is showing; both configurable | php | each mode; overridden config | rsx-theme-*, rsx-dark; the overrides | implemented | 2026-10-11 |
| THEME-ATTRS | app-declared attributes are answered per resolved theme, whatever the mode | php | declared dark/light sets, mode=2 | each set | implemented | 2026-10-11 |
| THEME-ATTRS-NONE | an app declaring no vocabulary is valid | php | empty sets | empty | implemented | 2026-10-11 |
| THEME-BODY-CLASS | rsx_body_class() carries no theme | php | mode=1 | no rsx-dark, no rsx-theme-* | implemented | 2026-10-11 |
| THEME-CHANGED | set_mode reports whether the resolved answer moved | php | light->dark, dark->dark | true, false | implemented | 2026-08-27 |
| THEME-INVALID | an unknown mode is refused | php | set_mode(99) | InvalidArgumentException | implemented | 2026-08-27 |
| THEME-NORMALIZE | null / non-numeric read as AUTO, not as light | php | 42, null, '1' | auto, auto, dark | implemented | 2026-08-27 |
| THEME-OPTIONS | the widget options come from the model enum | php | mode_options() | 3, light first | implemented | 2026-08-27 |
| THEME-HEAD-ORDER | autotheme.js follows the rsxapp block, is synchronous, and precedes every stylesheet | playwright | /_sys | index 1, not deferred, before first link | implemented | 2026-10-11 |
| THEME-PREPAINT | the theme is on <html> with no bundle script loaded; <body> carries none | playwright | bundle scripts aborted | mode class + resolved dark class on html | implemented | 2026-10-11 |
| THEME-OS-FOLLOW | auto follows a live OS scheme change without reload | playwright | emulated scheme change, both ways | class and attributes flip | implemented | 2026-10-11 |
| THEME-EXPLICIT | an explicit mode ignores the OS | playwright | light on dark OS, dark on light OS | unchanged | implemented | 2026-10-11 |
| THEME-SET-MODE | Rsx_Dark_Mode.set_mode() re-themes the booted page in place | playwright | dark, then auto on a light OS | dark, then light | implemented | 2026-10-11 |
