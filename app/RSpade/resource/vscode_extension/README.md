# RSpade

**Rapid Single Page Application Development Environment.**

**[rspade.org](https://rspade.org/)** · **[Documentation](https://docs.rspade.org/)** · [GitHub](https://github.com/rspade-framework)

RSpade is a batteries-included framework for building B2B web applications on
PHP and Laravel. This repository is not an empty skeleton — clone it, start it,
and you have a running multi-tenant SaaS with authentication, a client portal,
file handling, background jobs, live-updating pages and a component library,
which you then turn into your product.

It is deliberately opinionated: one way to build a page, one way to load a
record, one way to declare who may see it. There is no build step, no watcher and
no configuration layer — you edit a file and refresh the browser.

---

## What this extension does

RSpade finds classes by **name**, not by path. There are no imports, no
namespace declarations you write by hand, and no PSR-4 directory mapping — the
framework builds a manifest of everything in your project and resolves symbols
from it at runtime.

That is what makes RSpade pleasant to write and what makes a stock PHP setup
useless for reading it. Your editor sees a `use` statement that isn't there, a
class referenced from a JavaScript file that lives in PHP, and a method nothing
appears to call. It flags correct code as wrong and offers no navigation.

**This extension asks the running RSpade server the same questions the framework
asks itself.** Instead of guessing from file paths, it queries the live manifest
— so navigation and formatting are as accurate as the framework's own resolution,
and stay accurate as you move files around.

It activates only in an RSpade project, and it is silent everywhere else.

---

## Navigate

**Go to Definition works across the language boundary.** `F12` on almost any
identifier resolves through the manifest:

| You're looking at | It goes to |
|---|---|
| `Rsx::Route('Contacts_Controller')` / `Rsx.Route(...)` | the controller, or the exact method |
| `User_Controller.fetch_all()` **in JavaScript** | the PHP controller |
| `class Contacts_Grid extends DataGrid_Abstract` | the parent, PHP or JS |
| `<Define:My_Card extends="Card_Abstract">` | the component it extends |
| `$data_source=Frontend_Controller.fetch_data` | the class, then the method |
| `<%= this.data.users %>` | the current component's own member |
| `rsx_view('frontend.dashboard')`, `@rsx_extends(...)` | the Blade template |
| `'include' => ['jqhtml', 'frontend']` | the bundle definition |
| `href="/contacts"` | the controller that serves that URL |
| a file path written in a comment | that file |

Resolution is ordered per context — inside a `.jqhtml` file a bare class name
tries components first, then JavaScript, then PHP, because that is the order the
framework itself would resolve it in.

**A file opened through a symlink redirects to its real path.** `system/rsx/` is
a symlink to `rsx/` that the framework needs; opening a file through it silently
sends you to the real one, so you never edit a copy that looks right and saves to
the wrong place. Any symlink inside the workspace behaves the same way - the
redirect is resolved with the real path on disk, not from a list of known names.

---

## Read

**Framework attributes are highlighted** — `#[Ajax_Endpoint]`, `#[Route]`,
`#[Auth]`, `#[Task]`, `#[Relationship]` and friends are the load-bearing
declarations in an RSpade class, and they read as declarations rather than as
comments.

**Convention methods stop looking dead.** `on_app_ready()`, `on_app_init()` and
the rest of the boot chain are called by name at runtime, so every "unused
method" warning about them is wrong. They get their own semantic colour and a
hover explaining when the framework calls them.

**jqhtml lifecycle methods are checked for the right shape.** `on_create()`,
`on_render()`, `on_load()`, `on_ready()` and `on_stop()` each have a fixed
contract — some must be `async`, some must not be — and getting it wrong fails at
runtime in ways that are tedious to trace. Declare one incorrectly and the hover
tells you immediately.

**`that` is highlighted as the convention it is**, so the `const that = this`
pattern reads distinctly from an ordinary local.

**The `use` block is collapsed when a file opens.** RSpade resolves classes by name
through the manifest, so the import list is maintained for you and carries nothing
you act on — in a framework class it is often longer than the code beneath it.
Expand it and it stays expanded. Turn it off with `rspade.foldUseStatements`.

**Folder colours** mark `rsx/` — your code — apart from `system/`, which is the
framework and which you do not edit.

---

## Write

**PHP and Blade formatting on save**, performed by the server using the project's
own formatter. The result is identical to what the framework's own tooling
produces, because it *is* the framework's own tooling — there is no second
formatter configuration to keep in agreement.

**Namespaces follow files that move.** Drag a class in the explorer and its
namespace is rewritten to match where it landed. RSpade resolves classes by name,
so a stale namespace is not a compile error — it is a class that quietly stops
being found. This closes that gap.

**Optional: filenames follow classes.** With `auto_rename_files` enabled in
`config/rsx.php`, saving a file renames it to match its class name, `@rsx_id` or
`<Define:>` tag, following RSX naming conventions. Off by default; files marked
`@FILENAME-CONVENTION-EXCEPTION` are skipped.

---

## Refactor

Three project-wide refactors, run by the framework's AST tooling rather than by
find-and-replace, from the editor context menu:

- **Rename class** — every reference, including `Rsx::Route()` strings that name
  the controller
- **Rename method** — across PHP and JavaScript call sites alike
- **Sort class methods** — reorders a class into RSpade's conventional order

**Copy Relative Path from Project Root** (`Ctrl+Shift+Alt+C`) copies the path the
framework refers to a file by, which is the one worth pasting into a comment,
config or prompt.

---

## Live git status

Files are coloured in the explorer by their git state, read from the server. On a
containerised project the git history lives where the code lives, so the status is
correct without a local git installation or a checkout on your own machine.

---

## Works with the container stopped

The extension keeps track of whether the RSpade server is reachable, and pauses
only the features that need it — formatting, definitions, git status, refactors.
Highlighting, hovers, lifecycle checks and folder colours are computed locally and
keep working.

A status bar item appears when the connection drops and reconnects on click; the
extension also retries on its own. Nothing spams you with errors while the
container is down.

---

## Companion extension

Install **JQHTML** alongside this one for `.jqhtml` template syntax highlighting.
The two are aware of each other: component navigation from Blade and JavaScript
files uses the jqhtml extension's index when it is present.

---

## Requirements

An RSpade project, and the RSpade development server running for the
server-backed features. The extension finds the project and the server on its
own — there is nothing to configure.

<sub>`rspade.serverUrl` exists as an override for unusual setups, and
`rspade.enableFormatOnMove` turns off namespace-on-move. Neither is normally
needed.</sub>

---

Provided by © 2026 [HansonXyz](https://github.com/hansonxyz) · MIT
