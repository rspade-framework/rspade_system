---
name: audit-session
description: "Attributing logged activity to who did it and from where - Session::get_audit_session_id() to record, Session::get_audit_session() to read one back, Audit_Session::join() and Audit_Session::column() to show, search and sort audit fields (IP address, user agent, identity, impersonator) in a datagrid query. Use when building an activity log, action log, audit trail or export record; adding an audit_session_id column; showing the IP address or browser behind a logged action; deciding between get_session_id() and get_audit_session_id(); or when a stored session id no longer resolves."
---

# Audit sessions

A record of something somebody **did** - an activity log row, an export record, a revision - has to say who did it and from where, and keep saying it for as long as the record exists. An **audit session** is the framework's permanent record of that: one row per distinct **acting context**, minted on demand, never deleted.

## Two ids, two jobs

| | `Session::get_session_id()` | `Session::get_audit_session_id()` |
|---|---|---|
| what it is | the browser's CURRENT session row | a separate number - a permanent record of the acting context |
| for | state that belongs to the session and should die with it (a per-browser preference, a half-finished wizard) | the HISTORY of what somebody did |
| lifetime | deleted by cleanup, deleted when a command ends, **replaced at every sign-in** | **never deleted** |
| works in a command / task / API call | no usable id | yes |
| a table of yours storing it | `FOREIGN KEY (session_id) REFERENCES _sessions (id) ON DELETE CASCADE` - the ONE reference an application makes to a system table | a plain `BIGINT` column; no foreign key |

**Never store `get_session_id()` on a record of activity.** The id stops resolving - and for a command, a task or an API call it never resolved at all.

For session-scoped state, reach for `Session::put_value()` before a table of your own: its values follow the browser across a sign-in, and rows keyed to a session id do not (the browser gets a new one).

## Recording

```php
$log = new Action_Log_Model();
$log->description = 'exported contacts';
$log->audit_session_id = Session::get_audit_session_id();   // int, always
$log->save();
```

- Creates **no session**. Safe on any code path.
- **Works everywhere**: a browser request, a Bearer-key API call, a command, a task.
- **The same context answers the same id.** A context is the site, the staff identity, the portal identity and its site, both impersonators, the client address, the session row and the user agent. When any of them changes - a sign-in, a site switch, an impersonation, a new address, a different browser - the next call answers a new audit session.
- One request resolves its context once, however many rows it writes.
- The framework stamps it on every recorded unit of work itself (`_transactions.audit_session_id` - skill `rspade:revisions`).

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
session_row_id, device_id, session_type_id,
first_seen_at, last_seen_at
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

## Grouping what was done

| scope | by | answers |
|---|---|---|
| one acting context | the audit session id itself | this identity, from this address, in this session |
| one sign-in | `session_row_id` | everything done during one signed-in session |
| one browser across sign-ins | `device_id` | the whole picture for an investigation |

`session_row_id` and `device_id` are correlation numbers, not references: they keep grouping correctly after the session and the device record are gone. In a joined query they are `Audit_Session::column('session_row_id')` / `column('device_id')`.

## Gotchas

- **It is not the session id.** Do not compare an audit session id with `get_session_id()`, and do not pass one to the session-termination functions.
- **`null` user agent and address are normal** - a command or a task has neither. `session_type_id` says which kind of caller it was (`Session::TYPE_WEB` / `_API` / `_CLI` / `_PLAYWRIGHT`).
- **Under impersonation** the audit session carries BOTH the identity being acted as and the impersonator. Show the impersonator; that is who did it.
- **It fails loudly.** `get_audit_session_id()` returns an `int` and throws if the database cannot record it - the audit row could not have been written either.
- **No foreign key into `_audit_sessions`.** The row is never deleted, so there is nothing for a constraint to protect.

## Full reference

`php artisan rsx:man session` (AUDIT SESSIONS). Related: `rspade:session-auth` (sessions, sign-in, lifetime), `rspade:revisions` (the change history the framework attributes itself).
