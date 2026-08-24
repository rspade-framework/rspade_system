# RSpade — Quick Start

Getting a fresh RSpade project running, and the handful of commands you will use
every day. Everything beyond that is `php artisan rsx:man` (run it with no
argument to list every topic).

## How the tree is laid out

```
<project>/            your application repository
├── rsx/              YOUR code - modules, models, theme, services
├── system/           the RSpade framework - a git SUBMODULE, machine-owned
├── storage/          volatile state (build artifacts, caches, locks)
├── .env.dist         the TRACKED default settings (yours to curate)
└── .env              this machine's configuration (never committed)
```

`system/` is a **git submodule** tracking the published framework repository.
`rsx:framework:pull` moves that pointer to a newer release and commits the move
as one line. **Never hand-edit anything under `system/`** - every update resets
the whole tree. Your work lives in `rsx/`; a framework class you need to change
is changed by a class override in `rsx/` (`rsx:man class_override`).

## Installation

Development runs **inside the RSpade container** - the framework refuses to run
development mode anywhere else. You need Docker and git; no PHP, Node or MySQL
on your machine.

```bash
git clone --recurse-submodules <your-project-remote> my-app
cd my-app
bash system/app/RSpade/resource/docker/build.sh
docker compose up
```

`<your-project-remote>` is this application's own repository - the URL your
team pushes to. Starting a brand-new application instead? Clone the starter:
`git clone --depth 1 --recurse-submodules https://github.com/rspade-framework/rspade my-app`.

**`--recurse-submodules` is not optional.** The framework is the `system/`
submodule; a plain clone leaves it empty and nothing runs. Already cloned
without it? `git submodule update --init --recursive`.

Then open **http://localhost:8080** and follow the setup screens: the
application asks for its own address (`APP_URL`) and for the first account.
There is no `.env` to prepare and no install step:

- `.env` is created from `.env.dist` on first boot, and every key you later add
  to `.env.dist` reaches `.env` on the next development boot (`rsx:man app_mode`).
- `APP_KEY` is minted in development when it is empty.
- The framework's Composer and npm dependencies are **committed inside
  `system/`** and arrive with the submodule. Your own application packages are
  added through `rsx:composer` / `rsx:npm` (see Dependencies below), never by
  running `composer` or `npm` by hand.

Everything else - `migrate`, the schema, the first user - happens through those
screens and through `php artisan` inside the container:

```bash
docker compose exec app bash
php artisan rsx:health
```

`rsx:health` verifies dependencies, services and environment. Exit code 1 means
a genuine FAIL; WARN and INFO lines are advisory, and a WARN row names the
`rsx:heal <target>` that fixes it.

A production or debug host runs a **sealed build** outside the container:
`rsx:man app_mode` and `rsx:man prelaunch_checklist` are the path there.

## Updating the framework

```bash
php artisan rsx:git pull         # bring in your team's application changes
php artisan rsx:framework:pull   # bring in a new framework release
php artisan migrate              # apply any schema the release added
```

`rsx:framework:pull` raises a maintenance window, checks the new release out
into the `system/` submodule, commits the pointer move as one dedicated
framework-update commit, rebuilds, and runs any environment updates the release
shipped. It is not a merge and not a stash. If it reports pending
`upstream_changes` documents, those are manual actions the release needs from
you:

```bash
php artisan rsx:framework:upstream_changes          # list pending
php artisan rsx:framework:upstream_changes:show <name>
```

Full contract: `php artisan rsx:man rsx_upstream`.

**Use `php artisan rsx:git <any git command>` instead of bare `git`.** It is a
transparent proxy - same subcommands, flags, exit codes, stdin and TTY - that
keeps the checked-out framework in step with the submodule pointer your
repository records (a teammate's framework update lands on your box the moment
you pull it). Details: `rsx:man rsx_git`.

## Your first module

```bash
php artisan rsx:app:module:create welcome
```

The name must be lowercase letters and underscores only. This creates an SPA
module — the pattern most real screens use — plus its `index` feature:

```
rsx/app/welcome/
├── welcome_bundle.php            module bundle
├── welcome_spa_controller.php    #[SPA] bootstrap (gated)
├── Welcome_Spa_Layout.js         persistent layout
├── Welcome_Spa_Layout.jqhtml     layout template ($sid="content")
└── index/
    ├── Welcome_Index_Action.js       the screen
    ├── Welcome_Index_Action.jqhtml   its template
    └── welcome_index_controller.php  Ajax endpoints (gated)
```

Visit `/welcome`. Add more screens with:

```bash
php artisan rsx:app:module:feature:create welcome about
```

which writes `rsx/app/welcome/about/` with an `About_Index_Action` pair and a
gated `welcome_about_controller.php`.

Read `rsx:man spa` and `rsx:man crud` next, and look at the template app's
`rsx/app/frontend/clients/` module, which is the canonical CRUD reference.

For a public / server-rendered page (login, marketing, SEO), pass `--blade` to
either command to get the Blade module ladder instead — a `#[Route]` controller
plus `.blade.php` / `.js` / `.scss`. See `rsx:man module_organization`.

Components:

```bash
php artisan rsx:app:component:create --name=my_widget_component --module=welcome
```

The name must end in `_component`. Use `--path=` to place it anywhere, or
`--module=` (optionally with `--feature=`) to place it inside a module.

## Essential commands

### Development

```bash
php artisan rsx:check              # code-quality rules
php artisan rsx:routes             # every registered route
php artisan rsx:debug /route       # render a route headlessly, with JS
php artisan rsx:test               # run your application's tests
php artisan rsx:manifest:build     # rebuild the index (rarely needed by hand)
```

You do not run a build step. Files compile on change in development, and
bundles compile just-in-time on the web request.

`rsx:debug` is the workhorse for verifying a change actually renders:

```bash
php artisan rsx:debug /clients --user=1 --console
php artisan rsx:debug /clients --user=1 --screenshot-path=out.png
```

### Database

```bash
php artisan make:migration:safe create_table_name
php artisan migrate
php artisan db:query "SELECT * FROM users" --json
```

Migrations are forward-only — no `down()`, no rollback.

### Background work

```bash
php artisan rsx:task:list          # task definitions the manifest discovered
php artisan rsx:tasks:list         # running instances + schedule health
```

Install the cron tick once per environment:

```
* * * * * cd /path/to/project && php artisan rsx:task:process
```

### Dependencies

Your application layers its own packages at the project root, separate from the
framework's committed set:

```bash
php artisan rsx:composer require <pkg>    # add a PHP package
php artisan rsx:npm install <pkg>         # add a JS package
```

Never add packages by running `composer`/`npm` inside `system/`. Packages the
framework already ships (e.g. `guzzlehttp/guzzle`) are not duplicated —
requiring one records it as provided-by-framework instead of installing a
second copy. Full model: `php artisan rsx:man dependencies`.

## Documentation

RSpade documentation is not a folder of markdown files. It is two systems:

- **`php artisan rsx:man`** — the manual. Run it with no argument to list every
  topic; `rsx:man <topic>` to read one. Start with `rspade`, `routing`,
  `jqhtml`, `crud`, `auth_gates`, and `framework_divergences` (what RSpade
  changes about Laravel, and what will therefore surprise you).
- **The AI knowledge tree** under `system/app/RSpade/docs/` — always-on
  guidance plus on-demand skills, wired into Claude Code automatically. The
  first time you open the project, Claude Code asks once to trust this
  workspace's plugin; accept it to load the `rspade:*` skills. Declining breaks
  nothing — you simply lose the on-demand skills.

Any command's `--help` shows its full signature:

```bash
php artisan rsx:app:module:create --help
```

## Getting help with a problem

```bash
php artisan rsx:health          # is the environment sane?
php artisan rsx:check           # is the code within the rules?
php artisan rsx:debug /route --console   # what does the page actually do?
```

Between those three, most problems name themselves. RSpade is built to fail
loud: if something is wrong, it is designed to say so rather than degrade
quietly.
