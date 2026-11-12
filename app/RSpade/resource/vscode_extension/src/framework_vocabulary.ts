/**
 * The framework's declarative vocabulary: every PHP attribute and JS decorator the
 * framework reads, with the hover text shown for it.
 *
 * ONE list. The PHP and JS recognizers (rspade_recognizers.ts) only produce a token
 * for a name in this file, the semantic-token providers colour exactly those tokens
 * and the hover provider describes exactly those tokens - so a name that is coloured
 * always has a tooltip, and a misspelled attribute reads as an uncoloured one.
 *
 * A name belongs here only when the framework READS it (a manifest support module, a
 * directive table, a registry or a code-quality rule). Attributes have no PHP class
 * and decorators no definition the editor can jump to, so this static text is the
 * documentation the editor has; keep each entry in step with the man page it cites.
 *
 * `docs` entries are spelled `rsx:man <topic>` or `skill rspade:<name>` and must
 * resolve to a SHIPPED man page or skill (the recognizer tests check both).
 *
 * No vscode import: this module is compiled into the recognizer tests.
 */

export interface Vocabulary_Entry {
    /** Where the name may be written. */
    applies: string;
    /** Its arguments, or 'none'. */
    args: string;
    /** What it does - one or two sentences. */
    summary: string;
    /** Where to read more: `rsx:man topic` / `skill rspade:name`. */
    docs: string[];
}

export type Vocabulary_Kind = 'php_attribute' | 'js_decorator';

export const PHP_ATTRIBUTES: Readonly<Record<string, Vocabulary_Entry>> = {
    // ---------------------------------------------------------------------
    // Auth and dispatch surfaces
    // ---------------------------------------------------------------------
    Auth: {
        applies: 'Class or method - every #[Route], #[SPA], #[Portal_Route], #[Ajax_Endpoint], #[Ajax_Endpoint_Model_Fetch] and #[Api_Endpoint] surface',
        args: "Variadic check names: `#[Auth('is_logged_in', 'can_manage_users')]`. Built-ins: `'public'`, `'closed'`, `'is_logged_in'`, `'is_sysadmin'`, `'can_impersonate'`, `'is_framework_developer'`.",
        summary: 'The mandatory gate on a dispatchable surface: every named check must pass (AND). A class-level #[Auth] covers every surface in the class; a method-level one only narrows. A surface without a gate fails the manifest build. Never on a #[Companion_Endpoint].',
        docs: ['rsx:man auth_gates', 'skill rspade:auth-gates'],
    },
    Auth_Check: {
        applies: "Public static, parameterless `: bool` method on the realm's Permission class",
        args: 'none',
        summary: 'Makes the method a check name usable in #[Auth] and @auth, exported to the browser as `Permission.<name>()`. Evaluated live on every ask - keep it cheap. Rules that depend on WHICH record belong in the body, not in a check.',
        docs: ['rsx:man auth_gates', 'rsx:man acls', 'skill rspade:auth-gates'],
    },
    Auth_Realm: {
        applies: 'Class',
        args: "`'staff'`, `'portal'` or `'any'`",
        summary: "The auth realm the class's surfaces are gated in. Without it: `portal` for a file under a portal root, else `staff`. A realm mismatch denies before any check runs.",
        docs: ['rsx:man auth_gates', 'skill rspade:auth-gates'],
    },
    Route: {
        applies: 'Public static controller method `(Request $request, array $params = [])` - repeatable',
        args: "`'/path/:param'`, `methods: ['GET', 'POST']` (GET and/or POST only; default `['GET']`)",
        summary: 'A server page route. `:param` values arrive in `$params`; a handler serving both verbs branches on `$request->is_post()`. URLs are always built with `Rsx::Route()`.',
        docs: ['rsx:man routing', 'rsx:man controller'],
    },
    Portal_Route: {
        applies: 'Public static controller method in the client portal - repeatable',
        args: "`'/path/:param'`, `methods: ['GET', 'POST']`",
        summary: 'A route in the client-portal realm; also the bootstrap a @portal_spa action names. URLs come from `Rsx_Portal::Route()`.',
        docs: ['rsx:man portal', 'skill rspade:portal-core'],
    },
    SPA: {
        applies: 'Public static method on the bootstrap controller of an SPA module',
        args: 'none',
        summary: 'The SPA bootstrap: serves every @route action whose @spa names this method, and its #[Auth] gates are what the server checks for those URLs. One per module.',
        docs: ['rsx:man spa', 'skill rspade:spa'],
    },
    Ajax_Endpoint: {
        applies: 'Public static controller method `(Request $request, array $params = [])`',
        args: 'none',
        summary: 'Browser-callable endpoint, called from JS as `await Controller.method({...})` - no route, no URL. A site-lock READER by default; declare #[Writer] when it writes.',
        docs: ['rsx:man controller', 'skill rspade:ajax-error-handling'],
    },
    Ajax_Endpoint_Model_Fetch: {
        applies: "A model's static `fetch($id)` (or a fetchable relationship)",
        args: 'none',
        summary: 'Opts the model into the ORM endpoint (`await Model.fetch(id)` in JS). Requires #[Auth]; record-level rules go in the `fetch()` body and a denial answers "not found".',
        docs: ['rsx:man model_fetch', 'skill rspade:model-fetch'],
    },
    Api_Endpoint: {
        applies: 'Public static method on an `Rsx_Api_Controller_Abstract` - repeatable',
        args: "`'/api/vN/path/:token'`, `methods: ['GET']` or `['POST']` (never both), optional `through: N`",
        summary: 'External bearer-token API endpoint. GET reads and must not write (API-GET-PURE-01); POST writes. Every `:token` needs an #[Api_Param]. Answers a JSON object.',
        docs: ['rsx:man external_api', 'skill rspade:external-api'],
    },
    Api_Param: {
        applies: 'An #[Api_Endpoint] method - repeatable, one per parameter',
        args: "`'name'`, `type:` `'string'|'int'|'float'|'bool'|'file'`, `required:`, `default:`, `description:`, `example:` - literals only",
        summary: 'Declares, validates and coerces one API parameter, and documents it on the docs page and in OpenAPI. `$params` holds only declared parameters.',
        docs: ['rsx:man external_api', 'skill rspade:external-api'],
    },
    Api_Versions: {
        applies: 'API controller class',
        args: '`through: N` or `every: true` (exactly one)',
        summary: "Serves the controller's endpoints from their own version through vN, or (`every: true`) at every version the application serves. An endpoint's own `through:` overrides it.",
        docs: ['rsx:man external_api', 'skill rspade:external-api'],
    },
    Api_Resource: {
        applies: 'API controller class',
        args: "`'Name'`",
        summary: "The resource the controller's endpoints are listed under on the docs page and in the OpenAPI tags - lets a v2 controller join its v1 sibling. Without it the name derives from the class name.",
        docs: ['rsx:man external_api', 'skill rspade:external-api'],
    },
    Companion_Endpoint: {
        applies: 'Public static method `(Request $request, array $params = [])`',
        args: "The companion's name: `'feed_watch'`",
        summary: 'An endpoint a companion application calls over a signed request (`POST /_companion/<name>/<method>`). No #[Auth] - the signature is the gate. A reader unless #[Writer] or #[Manual_Lock].',
        docs: ['rsx:man companion_apps', 'skill rspade:companion-apps'],
    },
    Portal_Impersonation_Readable: {
        applies: 'Portal #[Ajax_Endpoint] method',
        args: 'none',
        summary: 'Lets the endpoint run while staff "View as Client" impersonates a portal user (denied by default). Mark READ endpoints only - never a write.',
        docs: ['rsx:man portal', 'skill rspade:portal-core'],
    },
    FPC: {
        applies: 'A public GET #[Route] method',
        args: '`ttl:` minutes (or positionally); bare = until cleared',
        summary: 'Full page cache for a public page. Disabled in this release: parsed and recorded, but nothing is cached and each request logs a warning.',
        docs: ['rsx:man fpc'],
    },

    // ---------------------------------------------------------------------
    // Site locks
    // ---------------------------------------------------------------------
    Writer: {
        applies: 'Ajax, API, companion endpoint, or a #[Route] / #[SPA] / #[Portal_Route] method',
        args: 'none',
        summary: "Takes the site's WRITE lock and one transaction: committed when the handler returns, rolled back when it throws. Covers ONE discrete operation - anything longer is #[Manual_Lock].",
        docs: ['rsx:man site_locks', 'skill rspade:site-locks'],
    },
    Reader: {
        applies: '#[Route], #[SPA] or #[Portal_Route] method (refused on endpoints, which are readers already)',
        args: 'none',
        summary: "Takes the site's READ lock around a page route, which otherwise takes no lock.",
        docs: ['rsx:man site_locks', 'skill rspade:site-locks'],
    },
    Manual_Lock: {
        applies: 'The same surfaces as #[Writer]',
        args: 'none',
        summary: 'No automatic lock: the handler takes short `Site_Lock::write()` sections itself, one per discrete operation. For a sign-in that enters a site, and for work that takes more than a few moments.',
        docs: ['rsx:man site_locks', 'skill rspade:site-locks'],
    },
    Site_Lock_Exempt: {
        applies: 'Site-scoped model class (inherited by subclasses)',
        args: "`'<reason>'` (required)",
        summary: 'Turns off site-lock write enforcement for the model. Only for append-only view, audit and telemetry records - never business data.',
        docs: ['rsx:man site_locks', 'skill rspade:site-locks'],
    },

    // ---------------------------------------------------------------------
    // Proxority - at most one per method (PROXORITY-01)
    // ---------------------------------------------------------------------
    Unthrottled: {
        applies: '#[Route], #[SPA], #[Portal_Route] or #[Api_Endpoint] method',
        args: 'none',
        summary: 'Never queued by Proxority (still counted against the pool). For cheap, fast routes whose delay would itself be the failure.',
        docs: ['rsx:man proxority'],
    },
    Priority: {
        applies: '#[Route], #[SPA], #[Portal_Route], #[Api_Endpoint] or #[Ajax_Endpoint] method',
        args: 'none',
        summary: 'Jumps the Proxority queue ahead of every non-priority request, never past the per-address cap.',
        docs: ['rsx:man proxority'],
    },
    Media: {
        applies: '#[Route], #[Portal_Route] or #[Api_Endpoint] method',
        args: 'none',
        summary: 'Counted against the media half of the Proxority pool, the worker held until the response ends. For a route that streams a large body or accepts an upload.',
        docs: ['rsx:man proxority'],
    },

    // ---------------------------------------------------------------------
    // Tasks and commands
    // ---------------------------------------------------------------------
    Task: {
        applies: 'Public static method `(Task_Instance $task, array $params = [])` on an `Rsx_Service_Abstract`',
        args: "`'<description>'`",
        summary: 'A background task, started with `Task::dispatch()` / `Task::internal()`. Its return value is the return code (null/true/0 succeed) - a task never returns data.',
        docs: ['rsx:man tasks', 'skill rspade:background-tasks'],
    },
    Schedule: {
        applies: 'A #[Task] method',
        args: "Cron or a phrase: `'every 5 minutes'`, `'daily at 2am'`, `'maintenance'`",
        summary: "Runs the task on a recurring schedule, on the server timezone's wall clock. Daily-or-rarer housekeeping uses `'maintenance'` with #[Exclusive('maintenance')].",
        docs: ['rsx:man tasks', 'skill rspade:background-tasks'],
    },
    On_Build: {
        applies: 'A #[Task] method beside #[Exclusive] or #[Debounce] (never #[Run_As_Dispatcher])',
        args: 'none',
        summary: 'Dispatches the task once for every new build of the application, with no params - from the scheduler tick, never the build itself. In development that is every source change, so start with a cheap "nothing owed" check.',
        docs: ['rsx:man tasks', 'skill rspade:background-tasks'],
    },
    Exclusive: {
        applies: 'A #[Task] method - repeatable with different groups',
        args: "none, or a group name: `#[Exclusive('remote_sync')]`",
        summary: 'At most one running and one pending run of this task; with a group, serialized against every task in that group.',
        docs: ['rsx:man tasks', 'skill rspade:background-tasks'],
    },
    Debounce: {
        applies: 'A #[Task] method',
        args: 'none, or seconds: `#[Debounce(30)]`',
        summary: 'Single-instance per method AND params; calls made while a run is in progress coalesce into one follow-up, due the given seconds after the previous run completed.',
        docs: ['rsx:man tasks', 'skill rspade:background-tasks'],
    },
    Run_As_Dispatcher: {
        applies: 'A #[Task] method (never beside #[Schedule], #[Command] or #[On_Build])',
        args: 'none',
        summary: "The run executes bound to the sign-in that dispatched it - the asynchronous stand-in for a user's Ajax endpoint (exports, imports, mass edits). Dispatching it without a signed-in request throws.",
        docs: ['rsx:man tasks', 'skill rspade:background-tasks'],
    },
    Task_Access: {
        applies: 'A #[Task] method',
        args: "`'dispatcher'` or the name of a static check method on the same service, optional `realm:` `'staff'|'portal'|'any'`",
        summary: "Who may see and act on this task's runs in the browser. Without it a run is visible to developers only.",
        docs: ['rsx:man tasks', 'skill rspade:task-access'],
    },
    Command: {
        applies: 'A #[Task] method',
        args: "`'prefix:name', 'Description'` (never an `rsx:` prefix)",
        summary: 'Exposes the task as an artisan command; `--key=value` options become `$params`. An application writes no command classes.',
        docs: ['rsx:man task_commands', 'skill rspade:task-commands'],
    },

    // ---------------------------------------------------------------------
    // Events, realtime, health
    // ---------------------------------------------------------------------
    OnEvent: {
        applies: 'Public static method (application handlers live in /rsx/handlers/)',
        args: "`'event.name'`, `priority:` (lowest runs first)",
        summary: 'A handler for an event fired by `Rsx::trigger_action()` / `trigger_filter()` / `trigger_gate()` / `trigger_resolve()`. Runs inline in the request - hand heavy work to a task.',
        docs: ['rsx:man event_hooks', 'skill rspade:event-hooks'],
    },
    Emitter: {
        applies: 'Public static method `(int $site_id, array $filter)`',
        args: "`'Topic_Class'`, optional `'Model_Name'` constraint",
        summary: 'A realtime emitter: recomputes a derived value (a count, a badge, a total) after writes and pushes it to the topic\'s subscribers. The model constraint narrows it to watchers of that model.',
        docs: ['rsx:man realtime', 'skill rspade:realtime'],
    },
    Realtime_Touch: {
        applies: "A child model's belongsTo relationship method",
        args: 'none',
        summary: "A write to this record also notifies the PARENT record's realtime watchers.",
        docs: ['rsx:man realtime', 'skill rspade:realtime'],
    },
    Health_Check: {
        applies: 'Public static method taking no arguments, returning a row or a list of rows',
        args: "`'Label'`, optional `modes:` `'development'|'debug'|'production'` or a list (absent = every mode)",
        summary: 'A row in `rsx:health`, declared where its feature lives. Returns `[\'status\' => OK|WARN|FAIL|INFO, \'detail\' => ..., \'remediation\' => ...]`.',
        docs: ['rsx:man health'],
    },
    Health_Heal: {
        applies: 'Public static method taking no arguments',
        args: "`'target-name'` (required, unique)",
        summary: 'The remedy `rsx:heal <target>` runs. Creates only what is definitionally absent and REFUSES anything present but wrong. Returns `[\'status\' => HEALED|ALREADY_OK|REFUSED, \'detail\' => ...]`.',
        docs: ['rsx:man health'],
    },

    // ---------------------------------------------------------------------
    // Models and data
    // ---------------------------------------------------------------------
    Relationship: {
        applies: 'Model instance method returning a relation',
        args: 'none',
        summary: 'Marks the method as an ORM relationship - only marked methods are relationships. Eager loading stays blocked; load explicitly.',
        docs: ['rsx:man model', 'skill rspade:models'],
    },
    Revision_Parent: {
        applies: "A child model's belongsTo relationship method (both models `$revisions = true`)",
        args: 'none',
        summary: "Files this model's revisions under the parent record's history. One parent, not a cascade.",
        docs: ['rsx:man revisions', 'skill rspade:revisions'],
    },
    Blob_Reference: {
        applies: 'Model class (with `use Blob_Referencing`) - repeatable',
        args: "`'column'`, or `column:` plus `where_null:`",
        summary: 'Declares a column holding a `_file_storage` id, so the blob is never released while a row references it.',
        docs: ['rsx:man file_disposal', 'skill rspade:file-attachments'],
    },

    // ---------------------------------------------------------------------
    // Class-descriptive markers
    // ---------------------------------------------------------------------
    Replaceable: {
        applies: 'Method',
        args: 'none',
        summary: 'An override of this method may replace it outright, without calling `parent::` - the opt-out from PHP-PARENT-CHAIN-01.',
        docs: ['rsx:man class_override'],
    },
    Instantiatable: {
        applies: 'Class (inherited by descendants)',
        args: 'none',
        summary: 'Allows instance methods and instantiation for this class and its descendants. Classes are static-only by default.',
        docs: ['rsx:man coding_standards'],
    },
    Sealed: {
        applies: 'Property',
        args: 'none',
        summary: 'No descendant may redeclare this property, at any depth - `final` for properties.',
        docs: ['rsx:man actors', 'skill rspade:actors-and-authorship'],
    },
    Monoprogenic: {
        applies: 'Abstract class',
        args: 'none',
        summary: 'Only one generation of concrete descendants: a concrete class may not extend another concrete class in this hierarchy (MANIFEST-MONO-01).',
        docs: ['rsx:man documents'],
    },
};

export const JS_DECORATORS: Readonly<Record<string, Vocabulary_Entry>> = {
    route: {
        applies: 'Spa_Action class - repeatable',
        args: "`'/path/:param'`",
        summary: 'A URL pattern this SPA action serves; several are allowed.',
        docs: ['rsx:man spa', 'skill rspade:spa'],
    },
    layout: {
        applies: 'Spa_Action class - repeatable',
        args: "`'Layout_Name'`",
        summary: 'The layout the action renders in. Several form a sublayout chain, outermost first; a layout must hold a `$sid="content"` element.',
        docs: ['rsx:man spa', 'skill rspade:spa'],
    },
    spa: {
        applies: 'Spa_Action class',
        args: "`'Controller::method'`",
        summary: 'The #[SPA] bootstrap method that serves this action; its #[Auth] gates are what the server checks for the action\'s URLs.',
        docs: ['rsx:man spa', 'skill rspade:spa'],
    },
    portal_spa: {
        applies: 'Portal Spa_Action class',
        args: "`'Controller::method'`",
        summary: 'The portal counterpart of @spa: names the #[Portal_Route] bootstrap that serves the action.',
        docs: ['rsx:man portal', 'skill rspade:portal-core'],
    },
    auth: {
        applies: 'Spa_Action class (mandatory on every @route action)',
        args: "Variadic check names: `@auth('is_logged_in', 'can_manage_users')`",
        summary: 'The gate on an SPA action - the same check names and AND semantics as #[Auth]. The client gate is UX; the server re-checks every call the action makes.',
        docs: ['rsx:man auth_gates', 'skill rspade:auth-gates'],
    },
    title: {
        applies: 'Spa_Action class',
        args: "`'Page title'`",
        summary: 'A fixed browser title for the action. Override `page_title()` only for a data-dependent one.',
        docs: ['rsx:man spa', 'skill rspade:spa'],
    },
    spinner_until_ready: {
        applies: 'Spa_Action class',
        args: "`'uncached'` or `'always'` (anything else throws)",
        summary: "Hides the action behind the application's spinner until its whole component tree is ready. `'uncached'`: only when nothing is cached (a view page); `'always'`: every visit (an edit page).",
        docs: ['rsx:man spa', 'skill rspade:spinners'],
    },
    mutex: {
        applies: 'Async method',
        args: "none (per instance), or `'global_id'` (shared by every instance)",
        summary: 'Exclusive execution: a call waits until the previous call of the method has finished.',
        docs: ['skill rspade:js-decorators'],
    },
    debounce: {
        applies: 'Method',
        args: 'Delay in ms: `@debounce(250)` (250 is the standard)',
        summary: 'The core `debounce()` as a method decorator: the first call runs at once, calls during a run or the delay after it coalesce into ONE follow-up, and runs never overlap.',
        docs: ['rsx:man js_functions', 'skill rspade:rsx-stdlib'],
    },
};

/** The name as it is written in source: `#[Name]` or `@name`. */
export function vocabulary_display_name(kind: Vocabulary_Kind, name: string): string {
    return kind === 'php_attribute' ? `#[${name}]` : `@${name}`;
}

export function vocabulary_entry(kind: Vocabulary_Kind, name: string): Vocabulary_Entry | undefined {
    const table = kind === 'php_attribute' ? PHP_ATTRIBUTES : JS_DECORATORS;
    return Object.prototype.hasOwnProperty.call(table, name) ? table[name] : undefined;
}

/** The hover markdown for one name, or undefined for a name not in the vocabulary. */
export function vocabulary_markdown(kind: Vocabulary_Kind, name: string): string | undefined {
    const entry = vocabulary_entry(kind, name);
    if (!entry) {
        return undefined;
    }

    const label = kind === 'php_attribute' ? 'RSpade attribute' : 'RSpade decorator';
    const docs = entry.docs.map(reference => `\`${reference}\``).join(' - ');

    return [
        `**${vocabulary_display_name(kind, name)}** - ${label}`,
        entry.summary,
        `*Applies to:* ${entry.applies}`,
        `*Arguments:* ${entry.args}`,
        `*Read:* ${docs}`,
    ].join('\n\n');
}
