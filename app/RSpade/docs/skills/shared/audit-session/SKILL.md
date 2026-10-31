---
name: audit-session
description: "Attributing logged activity to who did it and from where - Session::get_audit_session_id() to record (keyed on user session x site x address x user agent x display zone; null in a command or plain task), Session::get_audit_session() to read one back, Audit_Session::join() and Audit_Session::column() to show, search and sort audit fields (IP address, user agent, identity, impersonator, timezone) in a datagrid query, and Audit_Session::list_page_for_user_session() for a per-sign-in detail screen. Use when building an activity log, action log, audit trail or export record; adding an audit_session_id column; showing the IP address or browser behind a logged action; building the detail page of a \"where you're signed in\" entry; deciding between get_session_id() and get_audit_session_id(); or when get_audit_session_id() answers null in a command or task."
---

# Audit sessions

A record of something somebody **did** - an activity log row, an export record, a revision - has to say who did it and from where, and keep saying it for as long as the record exists. An **audit session** is the framework's permanent record of that: one row per distinct **acting context**, minted on demand, never deleted.

## Two ids, two jobs

| | `Session::get_session_id()` | `Session::get_audit_session_id()` |
|---|---|---|
| what it is | the BROWSER (one row per browser; whoever is signed in on it changes) | a permanent record of the acting context |
| for | state that belongs to the browser and should die with it (a per-browser preference, a half-finished import) | the HISTORY of what somebody did |
| lifetime | a guest's is deleted; one that has had a sign-in is kept but made inactive when unused | **never deleted** |
| in a command / plain task | null | null - nobody is acting |
| in a DISPATCHED `#[Run_As_Dispatcher]` run (a worker) | the dispatching browser | the dispatching request's audit session |
| on an API call | the key's own session | yes - per caller address and agent |
| a table of yours storing it | nullable, `FOREIGN KEY (session_id) REFERENCES _sessions (id) ON DELETE CASCADE` - the ONE reference an application makes to a system table | a plain nullable `BIGINT`; no foreign key |

**Never store `get_session_id()` on a record of activity** - it names a browser, not who used it.

## Recording

```php
$log = new Action_Log_Model();
$log->description = 'exported contacts';
$log->audit_session_id = Session::get_audit_session_id();   // ?int
$log->save();
```

- Creates **no session**. Safe on any code path.
- **The key**: the acting USER SESSION (the request realm's sign-in - staff, or portal on a portal request; on an API call the key owner's API user session) x the site acted on x the client address x the user agent x the DISPLAY ZONE (`Rsx_Time::get_user_timezone()` and its source - `explicit` / `browser` / `site` / `server`). A guest's act is keyed on the browser row instead (or on nothing, for a visitor with no row). When any of them changes - a sign-in, a site switch, an impersonation, a new address, a laptop that travelled to another zone - the next call answers a new audit session, so a record always points at the zone it was written in. The row also records the identity, both impersonators, the browser (`session_id`), the session type and the zone (`timezone`, `timezone_source`; a `site` or `server` source means the framework did not know where the person was - never show it as their location).
- **NULL where nobody is acting**: a command, a plain task and the `rsx:debug` harness record none. **A column storing it is nullable**, and a screen shows a null as "system" or blank. A dispatched `#[Run_As_Dispatcher]` run answers the audit session stamped at dispatch (run INLINE from a command or test it is the caller's: null), so its writes count under the requester's own context (address, agent, browser).
- One process resolves a context once, however many rows it writes; `last_seen_at` advances at most once a minute.
- The framework stamps it on every recorded unit of work itself (`_transactions.audit_session_id`, nullable - skill `rspade:revisions`).

Migration for your own table: `audit_session_id BIGINT NULL` with an index. Nothing more.

## Reading it back

**Never query `_audit_sessions`, `_user_agents` or `_ip_addresses`.** They are the framework's tables (every `_`-prefixed table is - `rsx:man database_schema_architecture`), and **their columns are not a contract**: where a value is stored can change between releases - the IP address and the user-agent string already live in tables of their own, and the audit row holds only their ids. Two calls are guaranteed to keep answering, and they are the only supported ways to get the data:

### One audit session

```php
$context = Session::get_audit_session($log->audit_session_id);   // array, or null if no such id
```

```
id, site_id, login_user_id, portal_user_id, portal_site_id,
impersonator_login_user_id, impersonator_user_id,
ip_address, user_agent (the full string),
session_id (the browser), user_session_id, session_type_id,
timezone, timezone_source, first_seen_at, last_seen_at
```

### Many at once - a join onto your own query

For a grid that shows the address and browser beside each logged act, and lets the user search and sort by them:

```php
use App\RSpade\Core\Session\Audit_Session;

$query = Action_Log_Model::query();
Audit_Session::join($query, 'action_logs.audit_session_id');

// filter and sort on audit fields with column() - never a table name
if ($search !== '') {
    $like = '%' . addcslashes($search, '%_\\') . '%';
    $query->where(function ($where) use ($like) {
        $where->where(Audit_Session::column('ip_address'), 'like', $like)
              ->orWhere(Audit_Session::column('user_agent'), 'like', $like);
    });
}

$query->orderBy(Audit_Session::column('first_seen_at'), 'desc');

foreach ($query->limit(25)->get() as $log) {
    $log->audit_ip_address;
    $log->audit_user_agent;          // the full string
    $log->audit_login_user_id;
    $log->audit_impersonator_login_user_id;
}
```

- **`Audit_Session::join($query, $column)`** - `$column` is YOUR qualified column (`'table.column'`) holding the audit session id. Every field of `get_audit_session()` is added to the select as **`audit_<name>`**.
- **A LEFT join**: a row with no audit session is still listed, its `audit_*` values null.
- A query that selected nothing explicitly keeps its own table's columns; the rows are still your models.
- Works on an Eloquent builder and on a query builder. Call it once per query.
- **`Audit_Session::column($name)`** - the expression for one field, for `where()` / `orderBy()` / `groupBy()`. `$name` is a `get_audit_session()` key; anything else throws.

**The field names are the contract; the tables and columns behind them are not.** Code written against `audit_<name>` and `column()` keeps working when the storage moves. Code that names `_audit_sessions.ip_address` does not.

A table of your own that records a bare client address (not a whole acting context) stores `Ip_Address::id($ip)` and reads it back with `Ip_Address::join($query, 'table.column', 'prefix')` or `describe()` - `rsx:man ip_addresses`.

### The audit sessions of one sign-in - a detail screen

A "where you're signed in" entry (a `get_sessions_for_user()` row - its `id` is a user session id) opens onto the addresses and user agents that sign-in acted from:

```php
$page = Audit_Session::list_page_for_user_session([
    'user_session_id' => $user_session_id,   // REQUIRED
    'filter' => $search,                     // IP address or user agent
    'sort' => 'last_seen_at',                // last_seen_at | first_seen_at | ip_address
    'order' => 'desc',
    'offset' => 0,
    'limit' => 25,                           // 1..200
]);
// ['rows' => [get_audit_session() shape + device_summary], 'total' => int, 'offset' => 0, 'limit' => 25]
```

**It is ungated** - your endpoint first decides whether the caller may see that user session (the id must be one of the entries the screen lists for the account). Invalid input throws `InvalidArgumentException`. Rows exist only for contexts something ASKED for (a revision-recorded write, a dispatched task, your own log): a sign-in that only browsed has none. Worked examples: the `/_sys` user session detail (`_Sys_Audit_Sessions_DataGrid`) and the template's `settings/user_management/session_view/`.

## Grouping what was done

| scope | by | answers |
|---|---|---|
| one acting context | the audit session id itself | this sign-in, from this address and browser, on this site |
| one sign-in | `user_session_id` | everything one account did through one sign-in on one browser (all its sign-ins there, since a user session is reused) |
| one browser | `session_id` | every account's acts from that browser - the picture for an investigation |

Both are correlation numbers, not references: they keep grouping correctly whatever happens to the sessions. In a joined query they are `Audit_Session::column('user_session_id')` / `column('session_id')`.

## Gotchas

- **It is not the session id, nor a user session id.** Do not compare an audit session id with `get_session_id()`, and do not pass one to the session-termination functions (they take a user session id).
- **A null audit session id is normal** - a command or a plain task records none. `session_type_id` on a row says which kind of caller it was (`Session::TYPE_WEB` for a browser, `Session::TYPE_API` for an API key).
- **Under impersonation** the audit session carries BOTH the identity being acted as and the impersonator. Show the impersonator; that is who did it.
- **It fails loudly.** Where an audit session exists, `get_audit_session_id()` throws if the database cannot record it - the audit row could not have been written either.
- **No foreign key into `_audit_sessions`.** The row is never deleted, so there is nothing for a constraint to protect.

## Full reference

`php artisan rsx:man session` (AUDIT SESSIONS, THE THREE RECORDS). Related: `rspade:session-auth` (sessions, sign-in, lifetime), `rspade:revisions` (the change history the framework attributes itself).
