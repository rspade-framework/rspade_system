# app/RSpade/Sys - the framework's own application

This tree is an RSpade APPLICATION that happens to ship inside the framework: the
control panel served at `/_sys` (seven screens - dashboard, debug flags, tasks, email
& SMS, logs, sites, users; `app/sys/CLAUDE.md` has the per-screen detail), plus the
API console beside it. It is built exactly the way `rsx/` is built - a
module with a bundle, a SPA bootstrap controller, JS actions, a theme directory of
components - and it is scanned by the manifest like any other application tree
(`config('rsx.manifest.scan_directories')` lists `app/RSpade/Sys`).

```
Sys/
  theme/            _Sys_Theme_Bundle (Bootstrap + tokens), variables.scss,
                    theme.scss, vendor/bootstrap.scss, components/
  lib/              shared panel code (classes only): _Sys_Endpoint_Controller_Abstract,
                    _Sys_Enum_Words (enum values as lowercase words, site lookups)
  handlers/         #[OnEvent] handlers (none today)
  app/sys/          the panel module: bundle, controllers, layout, one dir per page
  app/apidocs/      the API reference console: bundle, blade shell, Ajax
                    controller, components/ - standalone, mounted by the
                    application's own route (see app/apidocs/CLAUDE.md)
```

## Every name here carries one leading underscore

`_Sys_Layout`, `<Define:_Sys_Section>`, `@rsx_id('_Sys_App')`. The single leading
underscore is the FRAMEWORK-APPLICATION PREFIX, reserved from `rsx/` exactly as a
`_`-prefixed table or column is reserved in the schema: it is what keeps the
framework's application out of the namespace an application is free to fill. The
shape lives in `App\RSpade\Core\Naming\Rsx_Identifier`; `NAME-RESERVED-01` enforces
both DECLARATION directions at manifest-build time (bare name here -> violation;
`_`-prefixed name under `rsx/` -> violation, because the manifest would read it as
a class override and silently serve the application's copy), and
`NAME-RESERVED-02` refuses a REFERENCE to any of these names from `rsx/`.

DIRECTORIES are the exception: they are lowercase and never `_`-prefixed, because
the namespace generator PascalCases directory segments. `Sys/app/sys/X.php` is
`App\RSpade\Sys\App\Sys`, `Sys/theme/X.php` is `App\RSpade\Sys\Theme`, and a
namespace mismatch anywhere under `app/RSpade` is a fatal that is never auto-fixed.
Filenames are CASE-EXACT: the filename equals the class or component name.

## The bundle invariant

`_Sys_Bundle` and `_Sys_Theme_Bundle` NAME NO `rsx/` PATH AND NO APP-DEFINED
CLASS - `CONV-BUNDLE-04` (critical) enforces it. The panel is framework code
running beside somebody else's application; that application may restyle its
theme, redefine its Bootstrap build or delete a component at will, and the panel
must still come up. So the panel ships its own everything: its own Bootstrap build
(compiled from the framework's `system/node_modules/bootstrap`), its own palette,
its own components. The API console beside it (`app/apidocs/_Apidocs_Bundle.php`)
obeys the same invariant for the same reason, and reaches the SAME theme -
`_Sys_Theme_Bundle` plus `app/RSpade/Sys/theme` - so the framework's two
shipped surfaces are one product. `theme/theme.scss` is therefore the only
place a palette token is declared; a module never keeps a private copy.

Two mechanical consequences: SCSS `@import` is legal only in a file whose path
contains `/vendor/`, and `vendor/` is a never-recursed directory basename - so
`theme/vendor/bootstrap.scss` must be an EXPLICIT FILE include, never picked up
from a directory include. Bootstrap's JS arrives through the bundle's `npm` key
and its icons through `cdn_assets` (mirrored into the git-tracked `.cdn-cache`
store and served same-origin from `/_vendor/`).

## The panel toolkit (`theme/components/`)

The panel may use nothing from the template app, so its building blocks live here,
shared by the panel and the API console. Each component's `.jqhtml` docblock is its
contract; this is the roster.

| Component | What it is |
|---|---|
| `_Sys_Page_Scaffold` | the page shell (`<Slot:main>`): max width, padding, block gap |
| `_Sys_Page_Header` | a record heading: `$title`, `$subtitle`, `$icon`, `<Slot:meta>`, `<Slot:actions>` |
| `_Sys_Section` | a `.card` section: `$title`, `$icon`, `$count`, `$flush`; `<Slot:actions>` + `<Slot:body>`, or a loose body |
| `_Sys_Stat_Grid` / `_Sys_Stat_Tile` | headline numbers: `$label`, `$value`, `$sub`, `$tone`, `$href` |
| `_Sys_Status_Badge` | a status pill; `$status` is a tone (ok/warn/fail/info/muted) or a task/mail/SMS queue word or login-identity status word, mapped once in `_Sys_Status_Badge.TONES`; unknown throws |
| `_Sys_Detail_List` / `_Sys_Detail_Item` | key/value facts; an empty `$value` renders a muted em-dash |
| `_Sys_Empty_State` | an empty region: `$icon`, `$title`, `$body`, `<Slot:cta>` |
| `_Sys_Tabs` | tab bar + one `<Slot:key>` panel per tab, active tab in the URL hash (`$hash`); fires `tab_change` |
| `_Sys_Code_Pane` | a monospace pane for `$json` or `$text` (`$pretty` re-indents JSON text); `_Sys_Code_Pane.pretty()` is the one pretty-printer, the console's response pane included |
| `_Sys_Form_Field` + `_Sys_Text_Input`, `_Sys_Textarea_Input`, `_Sys_Select_Input`, `_Sys_Checkbox_Input`, `_Sys_Checkbox_List_Input` (a multi-select as checkboxes, value = the list of checked values, All/None) | the panel's form inputs on the framework's `Rsx_Form` / `Form_Input_Abstract`; text inputs require `$max_length` (-1 = unlimited). A failed submit marks the input COMPONENT `.is-invalid` (Form_Utils finds it by `data-name`); each input's SCSS carries that to its inner control, so a new input needs the same rule |
| `_Sys_Modal` | the dialog: `alert`, `confirm`, `show({buttons})`, `form({component, ...})` driving a hosted `Rsx_Form`; Modal's semantics (dismiss = false, only a literal false keeps it open, Enter presses the default button), on the theme's Bootstrap modal |
| `_Sys_Not_Found` / `_Sys_Unauthorized` / `_Sys_Error` | the panel's error bodies - registered as its SPA error screens, and usable directly in a three-state page |
| `_Sys_DataGrid_Abstract` (+ `_Sys_DataGrid_Body`, `_Sys_DataGrid_Filter`) | the paginated table; see "Panel grids" below |

A page's top-bar buttons come from the action's `page_actions()` (see
`app/sys/_Sys_Layout.js`).

### Panel grids

A grid is three small files beside the screen that shows it: a PHP class, a
`<Define>`, and one Ajax endpoint on the screen's controller forwarding to
`fetch($params)`.

- **PHP** extends `App\RSpade\Sys\Theme\Components\_Sys_DataGrid_Abstract` and
  implements `__build_query(array $params)`, returning the SOURCE: an Eloquent
  Builder (records serialize through the model's `toArray()`), a Query\Builder
  (rows arrive as arrays: `DB::table()` for a `_`-prefixed system table with no
  model, or a model's query ended with `->toBase()` when the row must carry exactly
  the selected columns - wrap it in `without_site_scope()` for a site-scoped
  model, since `toBase()` applies the scopes there and then), or a list of
  associative rows for a small in-memory set such as a directory listing (sorted
  and sliced in PHP). Filtering happens in `__build_query()`, reading `$params`
  (`filter` is the search text; each declared filter key is a top-level param).
  Settings: `$default_per_page` (25), `$max_per_page` (100), `$default_sort`
  ('id'), `$default_order` ('desc'), `$sortable_columns` (FAIL-CLOSED: an
  unlisted key sorts by the default, an empty list means default order only),
  `$secondary_sort`/`$secondary_order` (tie-break, default id desc);
  `#[Replaceable]` `__map_sort_column()` (a joined query's qualified column) and
  `__transform_records()`. Response: `{records, page, per_page, total, total_pages,
  sort, order}`; per_page is clamped into [1, max] and a page past the end is the
  last page.
- **jqhtml** is `<Define:_Sys_X_DataGrid extends="_Sys_DataGrid_Abstract"
  $grid_key="x" $data_source=_Sys_X_Controller.datagrid_fetch $sort="id"
  $order="desc" ...>` with `<Slot:header>` (a `<tr>` whose sortable `<th>` carry
  the LITERAL `data-sortby="key"`), `<Slot:row>` (one `<tr>`, receives `row`;
  `data-href` makes every plain cell a link) and optionally `<Slot:empty>` and
  `<Slot:toolbar>`. `$search` adds the search box (200ms debounce);
  `$filters=([{key, label, options, default?}])` adds one select per entry, where
  `options` is `[{value, label}]` or an Ajax endpoint answering that list.
  `$base_params` (passed where the grid is used: `<_Sys_Site_Members_DataGrid
  $base_params=({site_id: _s.id}) />`) is a fixed scope sent with every request
  and never kept in the hash - a grid embedded in a record's screen. The
  `.jqhtml` docblock is the full contract.
- **JS** is needed only for behaviour beyond that; the base owns all state.

State lives in the URL hash under the declared, STABLE `$grid_key` - `<key>_page`,
`_sort`, `_order`, `_q`, `_f_<filter>` (a cleared defaulted filter is `~`) - so a
link addresses a filtered grid: `Rsx.Route('_Sys_Tasks_Action', {}, {tab: 'history',
tasks_f_status: 'failed'})` (the `tab` key is the screen's `_Sys_Tabs` `$hash`). The
first load shows a spinner; a later load keeps the previous page painted, dimmed,
until the next arrives. A zero-row answer shows "No matches" with
Clear filters when the search or a filter narrowed it, otherwise `<Slot:empty>`.
No selection, mass actions or export.

## The gate and the switch

Every dispatchable surface in the PANEL declares `#[Auth('is_sysadmin')]` (JS actions:
`@auth('is_sysadmin')`). `is_sysadmin` is a framework check on `Permission_Abstract`,
staff realm only; its body is `Session::is_developer()`, and a change of audience
lands there, not at any call site here.

ONE panel surface is the exception: `GET /_sys/stop-impersonating`
(`app/sys/_Sys_Impersonation_Controller.php`), gated `is_logged_in` on a controller of
its own, because while the Users screen's "Sign in as this user" is in effect the
signed-in identity is the impersonated one and never a developer. Its body refuses
unless the IMPERSONATOR is a developer. `Sys_Panel_Gate_Test` holds the exception list
at exactly that one entry.

`app/apidocs/` is outside the panel gate too, deliberately: the console is not a panel
screen. It has no route of its own - the APPLICATION mounts it - so its gate is
whatever that route carries, and `_Apidocs_Controller`'s Ajax endpoints stay
`#[Auth('public')]` because the framework cannot know what the app chose.

`config('rsx.sys_panel.enabled')` (default true) switches the panel off. No
manifest or dispatcher mechanism removes a route by config, so the refusal is ONE
`pre_dispatch()` returning `response_not_found()` (the 404 page on a page GET, the
not_found envelope on an Ajax call), on `lib/_Sys_Endpoint_Controller_Abstract`.
Both dispatch seams call `pre_dispatch()` statically on the concrete class (the
page Dispatcher and `Ajax::execute()`), so every panel controller EXTENDS that
base and inherits it; one that overrides `pre_dispatch()` calls the parent first
(PHP-PARENT-CHAIN-01). Attributes are not inherited, so each controller still
declares `#[Auth('is_sysadmin')]` at class level (the stop-impersonating controller:
`is_logged_in`). `Sys_Panel_Gate_Test` enumerates every surface under `app/sys/` and
holds all of this.

## Adding a page

1. `mkdir app/sys/<feature>` (lowercase, no underscore prefix).
2. `_Sys_<Feature>_Action.js`: `@route('/_sys/<path>') @layout('_Sys_Layout')
   @spa('_Sys_Spa_Controller::index') @auth('is_sysadmin') @title('<Title>')`,
   `class _Sys_<Feature>_Action extends Spa_Action`.
3. `_Sys_<Feature>_Action.jqhtml`: `<_Sys_Page_Scaffold><Slot:main>...`.
4. Add the nav entry to `_Sys_Layout.js` `on_create()`. Items are filtered
   through `Permission.can_access(item.route)`, so a tighter gate removes the link
   with no edit here. A detail screen under the entry's URL (`/_sys/tasks/:id`)
   needs no entry of its own: `_Sys_Sidebar_Nav` lights an entry on its own URL
   and on every URL beneath it (the dashboard at `/_sys` matches exactly).
5. The screen's PHP - its controller (extending `_Sys_Endpoint_Controller_Abstract`,
   `#[Auth('is_sysadmin')]`) and any grid class - lives in the screen directory,
   namespace `App\RSpade\Sys\App\Sys\<Dir>`. `app/sys/tasks/` is the worked
   example: a controller, a grid, a self-loading region, a detail action and a
   `_Sys_Modal.form()` dialog.

Nothing needs registering: `__DIR__` is already in `_Sys_Bundle`'s include list.

## Framework property: modified here, referenced nowhere

This tree is modified in ONE place - the RSpade monorepo, on a box with
`IS_FRAMEWORK_DEVELOPER=true`. Downstream all of `system/` is reset to the
upstream tip by `rsx:framework:pull`, so an edit here survives until the next
update and then vanishes with nothing reported; a downstream change to the panel
is a framework change request (`rsx:man framework_debug_and_contrib`).

And the rule pointing the other way: **an application must not USE the `_Sys_*`
or `_Apidocs_*` classes, components, templates or routes.** Not extend
`_Sys_Layout`, not render `<_Sys_Section>`, not call a `_Sys_*` controller
method, not hand-write a `/_sys` sub-path. Everything here may be renamed,
restructured or deleted in any release. The sanctioned references are a link to
the panel through `Rsx::Route('_Sys_Dashboard_Action')` guarded by
`Permission::can_access(...)`, and an impersonation banner's exit link through
`Rsx.Route('_Sys_Impersonation_Controller::stop')` - both published into every bundle
by `config('rsx.always_published_routes')`.

`NAME-RESERVED-01` checks DECLARATIONS; `NAME-RESERVED-02` checks REFERENCES,
and both are manifest-build fatals. NAME-RESERVED-02 fires when application code
under `rsx/` names a `_`-prefixed class, component, `@rsx_id` or static method
that the MANIFEST knows is declared under `app/RSpade/` - so it covers this whole
tree plus every other `_`-prefixed framework class, and it covers a framework `_`/`__`-prefixed STATIC on an
ordinarily-named class (`Ajax::_is_internal_call()`, `Rsx._escape_html(...)`).
The sanctioned carriers stay legal because they are STRINGS and the rule
never reads a string literal. Details: `rsx:man sys_panel`,
`rsx:man coding_standards`, skill `rspade:sys-panel`.

The framework's inventory commands hide this tree's names and files unless the
box is a framework developer (`Rsx_Identifier::is_visible_to_developer()` /
`is_path_visible_to_developer()`) - display only, nothing leaves the manifest.

## Living documentation

This file describes the tree as it is today. When the tree's contents or
conventions change, updating it is part of that change.
