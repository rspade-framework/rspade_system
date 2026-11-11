# Proxority

Proxority is a fair-queueing HTTP proxy that sits between the public nginx and the php-fpm
pool inside the RSpade container. It exists because php-fpm has a fixed number of workers
and serves whoever asks first: one client opening many connections, a crawler walking the
site, or a run of large downloads could otherwise occupy every worker while everybody else
waits. Proxority keeps one ledger for that pool and decides which pending request runs
next, so no single client address can take every worker, downloads and uploads share at most
half of a pool, crawlers a fifth, priority requests jump the queue, and everyone else takes
turns.

It is node core only - no npm dependencies:

| File | What it is |
|---|---|
| `proxority.js` | the server (the network around the policy) and the CLI client (`status`, `log`) |
| `lib/scheduler.js` | the ledger of one pool: caps, queues, round robin, priority. Pure - no I/O, no clock |
| `lib/classify.js` | what a request is: pool, client address, loopback, robot, media, priority, unthrottled; the pattern syntax |
| `lib/whitelist.js` | the periodic refresh of the application's directives |
| `lib/event_log.js` | the in-memory ring of the last 1000 events |
| `lib/pool_capacity.js` | reads `pm.max_children` from the pool files |
| `proxority-run.sh` | the supervisor entry point (`exec`s node, so supervisor's pid is the proxy) |

Tests: `system/app/RSpade/tests/proxority/` (`rsx:test --framework --group=proxority`).

**It is part of the container image, not of the project.** This directory is its only copy of
source; the image's Dockerfile installs it at `/opt/rspade/proxority/` (root-owned, readable),
supervisor runs it from there, and `/usr/local/bin/proxority` is the operator's command
(`node /opt/rspade/proxority/proxority.js "$@"`). It therefore runs whether or not an RSpade
checkout is mounted at `/var/www/html`. The one thing it reads from the project is the answer
of its whitelist refresh (below); with no checkout every refresh fails, the list stays empty,
and every request is throttled. A change here reaches a container by rebuilding the image.
The application-facing contract is `rsx:man proxority`.

Outside the RSpade container nothing here runs and nothing changes: the application's
directives are inert metadata, `/_ajax/_priority` answers like `/_ajax/_batch`, and nginx (or
whatever web server the host has) talks to php-fpm as it always did.


## The request path

```
client -> (reverse proxy / TLS terminator, if any)
       -> nginx :80 / :8000   PUBLIC. The only access log. Resolves the real client (realip).
            static files under system/public, /ws, /_build      served as before, never proxied
            /_compiled/*, /_vendor/* that exist on disk          served by nginx itself
            everything else bound for PHP                        -> 127.0.0.1:3300
       -> Proxority 127.0.0.1:3300                              decides WHEN the request runs
       -> nginx 127.0.0.1:3301 (the :80 flavour)
          or     127.0.0.1:3302 (the :8000 flavour: HTTPS=on, X-Forwarded-Proto https)
            access_log off; fastcgi to php-fpm's one pool
            (through @php, which pins X-Forwarded-Proto to the flavour)
       -> php-fpm
```

A URL ending in `.php` is answered 404 by every listener - RSpade has no `.php` URLs - so the
front controller is reached only through those locations, and "/" goes to the application through
the proxy like any other path, never through an index rewrite to `/index.php`.

| Port | Listener | Notes |
|---|---|---|
| 80, 8000 | public nginx | 8000 is the same site asserting https behind a TLS terminator |
| 3300 | Proxority | loopback only; only nginx talks to it |
| 3301 | loopback nginx, :80 flavour | Proxority's upstream for a request that arrived on :80 |
| 3302 | loopback nginx, :8000 flavour | Proxority's upstream for a request that arrived on :8000 |
| 3201 | loopback nginx | php-fpm status pages for the `/_sys` Performance screen; not part of this path |

The public listener sets `X-Rsx-Scheme: http` or `https` on the hop to Proxority. Proxority
picks the upstream by it (`https` -> 3302, anything else -> 3301) and strips it: it never
reaches PHP. The URI is the one the client sent (`proxy_pass ...$request_uri`, so an nginx
internal redirect never rewrites it).

**Request headers** are forwarded exactly as received, in order and spelling - `X-Forwarded-For`,
`X-Real-IP`, `Host`, `Cookie`, `Authorization`, `Range`, every `If-*` - except the hop-by-hop
ones, which describe the connection into the proxy and not the request: `Connection`, every
header the `Connection` header names, `Keep-Alive`, `Proxy-Connection`, `TE`, `Trailer` and
`Upgrade`. Dropping `Connection` matters: nginx sends `Connection: close` on every proxied
request, and forwarded it would close each upstream connection instead of returning it to
Proxority's keep-alive pool. `Content-Length` and `Transfer-Encoding` frame the body and are
always kept, even when a `Connection` header names them (so is `Host`).

**Response headers** are passed through as the upstream sent them - status line, every
`Set-Cookie` on its own line, `ETag`, `Content-Range`, `Content-Encoding`, `Vary`,
`Cache-Control`, `Location` - except `Connection`, `Keep-Alive` and `Transfer-Encoding`, which
node frames itself on the client connection. A 304 and the answer to a HEAD carry no body.

While Proxority is down, nginx answers its own 502 for every PHP-bound request: there is no
path around it to php-fpm. Static files, `/_compiled`, `/_vendor` and the realtime socket keep
working.

**Logging.** The public nginx writes the only access log (`/var/log/nginx/access.log`), once
per request, under the real client. The loopback listeners log nothing. Proxority writes no
access log: per-request traffic goes only into its in-memory event ring (below), and the
other events (start, stop, capacity, timeouts, upstream errors, client aborts, whitelist
refreshes) are also echoed to its stdout, which supervisor keeps - the container's stdout in
the image, `/var/log/supervisor/proxority.log` where a box configures a log file.


## The client address

The public nginx resolves the real client with its realip configuration
(`/etc/nginx/conf.d/rspade-realip.conf`, written by `rspade-trusted-proxies` at container
start): loopback plus the declared trusted proxies (`.env TRUSTED_PROXIES`, else the
container's `RSPADE_TRUSTED_PROXIES`), walking `X-Forwarded-For` right to left. It then sends
`X-Real-IP: $remote_addr` and `X-Forwarded-For: $proxy_add_x_forwarded_for` to Proxority.

Proxority schedules by `X-Real-IP`. It believes that header without a trusted-proxy list of
its own, because only nginx can reach its loopback port. A request with no `X-Real-IP` is keyed
on the address of the connection. An IPv4-mapped IPv6 address (`::ffff:1.2.3.4`) is the IPv4
address.

On the loopback hop nginx trusts 127.0.0.1 and walks the same `X-Forwarded-For`, which ends in
the address the public hop resolved - so php-fpm's `REMOTE_ADDR` is the real client, exactly
as when the public listener talked to php-fpm itself, and a forged entry a client wrote is
never reached.

**A deployment behind a reverse proxy MUST declare it as trusted.** Undeclared, every visitor
arrives from the proxy's one address, and Proxority schedules all of them as ONE client: the
whole site shares the per-IP cap.

**Loopback.** A request whose client address is loopback (`127.0.0.0/8`, `::1`) - something on
the box itself calling its own nginx with no `X-Forwarded-For`: `rsx:debug`, server-side
rendering, a test harness - is never queued and is NOT counted busy. See "Future issues".

**Companion calls are the exception: a client like any other.** A companion application calls
`http://127.0.0.1`, so its address is loopback, but a request under `/_companion/` is queued,
counted and round robined on that address with the ordinary per-IP cap - a chatty companion
waits its turn beside the site's visitors instead of taking the pool.


## The pool and its capacity

php-fpm runs ONE pool, `www` (`/etc/php/8.5/fpm/pool.d/www.conf`), and every PHP-bound
request - pages, Ajax, companion calls, the external API - lands in its one ledger. Fairness
between clients is the ledger's job: the caps and the round robin below.

The capacity N is its `pm.max_children`, read from the pool file at start. A missing or
unreadable file, or one with no whole-number `pm.max_children` of at least 1, fails the start
loudly: Proxority schedules against exactly the number of workers php-fpm runs, never a guess.

In the production image the container entrypoint sizes the pool (`rspade-sizing`) before
supervisor starts anything, so Proxority reads the sized value. To change it at run time:
edit the pool file, reload php-fpm, then send Proxority SIGHUP
(`supervisorctl signal HUP proxority`). SIGHUP re-reads the file; a growth starts whatever
now fits at once, a shrink stops nothing (running requests finish and the pool drains down to
the new N). A file that fails to read on SIGHUP keeps the current size and logs why.


## The rules

For a pool of N workers:

| Cap | Formula | N = 4 | N = 8 | N = 12 |
|---|---|---|---|---|
| per client address | `max(1, N - 1)` | 3 | 7 | 11 |
| media | `max(1, floor(N / 2))` | 2 | 4 | 6 |
| robots | `max(1, floor(N / 5))` | 1 | 1 | 2 |

- **A request counts against every cap that applies to it**: the per-IP cap always, the media
  cap when it is media, the robot cap when it is a robot.
- **Media** is a request to a route the application marks `#[Media]` (the framework's file
  routes: download, ZIP download, inline, preview, thumbnail, upload, `POST /api/v1/files`)
  or any request with `Content-Type: multipart/form-data`.
- **A robot** is a request whose `User-Agent` matches the hard-coded list in
  `lib/classify.js` (Googlebot, Bingbot, Baiduspider, Yandex, ... and finally anything saying
  `bot`, `crawler`, `spider` or `crawling`). Hard-coded on purpose: a robot is a robot
  whatever the application thinks.
- **Dispatch order when a worker frees**: first the oldest PRIORITY request that fits its
  caps; then round robin across client addresses, one request per address per turn, each
  address's own requests oldest first. A newly queued address joins the end of the rotation.
- **A request whose cap is full is skipped this round**, and the next one that fits runs in
  its place: a blocked download never stalls the pages queued behind it.
- **Priority never exceeds the per-IP cap.** It only jumps the queue. A request is priority
  when any of these holds:
  - its path ENDS in `/_ajax/_priority` - the bare channel, or a portal's under a path prefix
    (`/_portal/_ajax/_priority`). Always: Ajax endpoints are not in the whitelist, and the
    server refuses a call on that channel to an endpoint not marked `#[Priority]`, so the
    proxy grants nothing but queue order;
  - it matches a `#[Priority]` route pattern;
  - it is a REVALIDATION: a `GET` or `HEAD` carrying `If-None-Match` or `If-Modified-Since`.
    A revalidation is usually answered 304 with no body, so serving it first costs little and
    makes a returning page fast. Not `If-Match`, `If-Unmodified-Since` or `If-Range`, and not
    any other method: a header any client can add must not lift a write.

  A priority request keeps its other classes: a revalidation of a download is still media.
- **Unthrottled** requests (`#[Unthrottled]` routes, `/_compiled/*`, `/_vendor/*`, every file
  in an application `public/` directory) are dispatched at once and never queued - but they
  ARE counted: busy, the per-IP count, and the media and robot counts when those apply. So
  busy may exceed N; while it does, nothing queued starts until it is back under N.
- **Loopback** requests are dispatched at once and not counted at all (shown separately in
  `status` as "loopback in flight").

### Slot release

- **Media**: the worker is counted until the upstream response ENDS (the last byte has come
  out of nginx). A download holds its slot for as long as it streams.
- **Everything else**: the worker is released on the FIRST response byte - the response head.
  By then PHP has done its work; nginx and the proxy are only relaying the body.
- A client that leaves while queued is dropped from the queue. A client that leaves while its
  request runs aborts the upstream request and releases the worker.

### Streaming and the buffer

Responses always stream. At most `RESPONSE_BUFFER_BYTES` - a hard-coded 10 MB constant in
`proxority.js` - of one response sits in the proxy before the upstream is paused
(backpressure), and resumes when the client has read it down. php-fpm can hand over a 10 MB
page and move on while a slow client reads, and a 2 GB download never lands in memory.

Request bodies are not buffered by the proxy: nginx has already buffered the whole body
(`proxy_request_buffering on`) before Proxority sees the request, so an upload needs no
special handling.

### The two timeouts

Both are owner-specified (2026-11-03) and documented beside their constants in
`proxority.js`. Both bound a wait on the UPSTREAM NGINX - an external party that may never
answer:

| Bound | Value | Starts | Ends |
|---|---|---|---|
| connect | 60 s | the request is dispatched to the loopback nginx | the TCP connection is open |
| response head | 360 s | the connection is open (or reused) | the response head arrives |

- **Time in the queue never counts.** A queued request waits as long as fairness requires;
  node's own request and header deadlines are switched off for that reason.
- **Streaming a body never counts.** A download takes as long as the client reads.
- **On expiry** the client gets a 504 (or its connection is closed if a head was already
  sent), the worker slot is released for the next request, and a `timeout` event is logged.

The reasoning: these bound the one thing that can hang outside the proxy's control - the
upstream accepting the connection, and then producing an answer. A PHP request that has
produced nothing for six minutes is not going to be useful to the client waiting for it, and
holding its slot forever would remove a worker from the pool. Neither bound can cut off work
that is making visible progress, since the body stream is never timed.

An upstream that refuses the connection or fails is a 502, slot released, `upstream_error`
logged.


## The whitelist refresh

The application tells Proxority which routes are unthrottled, priority or media through a
hidden framework command:

```
php artisan rsx:proxority:routes --json
{"unthrottled": [patterns], "priority": [patterns], "media": [patterns]}
```

- Proxority runs it at start and then every 60 s, spawned WITHOUT a shell from the project
  root.
- **One run at a time, no timeout on it.** A run still going when the next interval comes is
  logged (`refresh_skipped`) and that interval is skipped, so a hung command is visible in the
  event log every minute and costs nothing else. The interval is a poll cadence, not a
  deadline. At shutdown a running refresh is sent SIGTERM.
- **Only a run that exits 0 with a well-formed answer replaces the list.** A non-zero exit, a
  spawn error, output that is not JSON, or a malformed pattern keeps the previous list and logs
  `refresh_failed`.
- **Maintenance**: while maintenance mode is up on disk the command prints
  `{"maintenance":true}` and exits 3. Proxority keeps the previous list
  (`refresh_maintenance`), because mid-maintenance the tree may be half-updated. Proxority has
  no other maintenance behaviour.
- **At boot the list is empty: everything is throttled** until the first accepted answer.
  That is the safe direction to be wrong in.

**What is listed** (`Core/Proxority/Proxority_Routes.php` documents it in full): every route
row whose surface declares a directive - `#[Route]`, the actions an `#[SPA]` bootstrap
serves, `#[Api_Endpoint]`, `#[Portal_Route]` and portal SPA rows - plus, always unthrottled,
`/_compiled/*`, `/_vendor/*` and every file in an application `public/` directory as an exact
path. Ajax endpoints are never listed.

**Pattern syntax** (`compile_pattern()` in `lib/classify.js`), matched against the raw path:

| Pattern | Matches |
|---|---|
| `/exact/path` | that path |
| `/files/:key` | one whole segment |
| `/files/:key/:name?` | an optional final segment |
| `/_compiled/*` | the prefix and anything after it (`*` only at the end) |

A route pattern richer than this is widened to the nearest proxy pattern, never narrowed. A
portal route appears under the `PORTAL_URL` prefix with its tenant placeholder kept as a
`:param` segment (`/_portal/:slug/...`), and bare where a portal is served at a host's root;
the proxy matches paths, never hosts.


## The application directives

PHP attributes, metadata only, read at manifest build. A method declares at most one; one on
any other surface fails the manifest build (`PROXORITY-01`).

| Directive | Honoured on | Effect |
|---|---|---|
| `#[Unthrottled]` | `#[Route]`, `#[SPA]`, `#[Portal_Route]`, `#[Api_Endpoint]` | never queued (still counted busy) |
| `#[Priority]` | the same, plus `#[Ajax_Endpoint]` | jumps the queue, never past the per-IP cap |
| `#[Media]` | `#[Route]`, `#[Portal_Route]`, `#[Api_Endpoint]` | counted against the media cap; the slot is held to the last byte |

**`/_ajax/_priority`** is the priority Ajax channel: the `/_ajax/_batch` envelope carrying
exactly one call to a `#[Priority]` Ajax endpoint. The generated controller stub carries a
priority flag; `Ajax.js` sends such a call alone to `/_ajax/_priority` and batches everything
else. The server refuses a body with more than one call (400) and answers a call to an
endpoint without `#[Priority]` with the not_found envelope. A `#[Priority]` endpoint sent
through `/_ajax/_batch` still runs there, unprioritised. Proxority treats the path itself as
priority - any path ending in `/_ajax/_priority`, so a portal's `<prefix>/_ajax/_priority` too -
and never reads the body.


## Status, log and the event ring

Proxority answers on a unix socket, `/run/proxority/proxority.sock` (it creates the directory
itself; root only). The CLI reads it:

```
proxority status
proxority log
proxority log --follow
proxority close --reason="..." [--holder-pid=N]
proxority open
```

- `status` - pid and uptime; listen and upstream addresses; the pool's capacity, the three
  caps, busy by class (normal, priority, media, robot, unthrottled), loopback in flight,
  queued (and how many are priority), the ten addresses with the most queued; the whitelist
  counts, when it was last accepted, and the last refresh outcome (and a refresh still running).
- `log` - the event ring, oldest first.
- `log --follow` - the ring, then every new event as it is logged, until interrupted.
- `close` - stop admitting EXTERNAL clients (every non-loopback address) and return once the
  external requests already admitted, queued or running, have finished - a wait with no
  deadline. While closed, such a request is answered `503` with `Retry-After: 30` and a body
  naming the reason; loopback callers (rsx:debug, a test harness's browser, companions) and
  the files nginx serves from disk are untouched. `--holder-pid` names the process the closure
  belongs to, and its kernel start time (`/proc/<pid>/stat` field 22) is read at close and kept
  with it: while closed, a request that would be refused first checks that the pid is still
  that process, and reopens the proxy when it is gone or the pid now names another process -
  so a closer that died never leaves the site closed. The
  state is in memory, so a restart always comes up open. `status` shows it.
- `open` - admit everyone again.

A sequential `rsx:test` inside the container closes the proxy for its run and opens it again
when the run ends (PHP side: `Core/Proxority/Proxority_Gate.php`; `rsx:man testing`).

All of them exit 1 with "Proxority is not running" when the socket does not answer. `--socket=`
points them at another socket.

The ring keeps the last 1000 events in memory - nothing is written to disk. Event types:
`start`, `stop`, `capacity`, `queued`, `dispatched`, `released`, `timeout`,
`upstream_error`, `client_abort`, `refresh_ok`, `refresh_failed`, `refresh_maintenance`,
`refresh_skipped`, `closed`, `drained`, `opened`, `refused`. `queued`, `dispatched`, `released`
and `refused` are per-request traffic and stay in
the ring only (echoing them would make stdout an access log); the rest are also echoed.

The socket protocol is newline-delimited JSON: a line `{"verb":"status"}` (or `log`, or
`follow`) answers `{"ok":true,...}`; `follow` then streams `{"event":{...}}` lines.
`{"verb":"close","reason":"...","holder_pid":N}` answers `{"ok":true,"closed":{...}}` once
drained (`ok:false` if it was reopened first); `{"verb":"open"}` answers
`{"ok":true,"was_closed":bool}`.


## Signals

- **SIGHUP** - re-read the pool sizes (above).
- **SIGTERM / SIGINT** - stop accepting new connections, stop the whitelist refresh, remove the
  status socket, and exit 0 once every request already inside has finished - queued ones
  included, which still run as workers free. A second SIGTERM / SIGINT exits at once.
- Supervisor's `stopwaitsecs` is the SIGKILL deadline on that drain (see the supervisor
  program's own comment; `killasgroup` makes the SIGKILL reach a running refresh child too).

A second Proxority started while one is serving refuses to start (its status socket answers);
a stale socket file left by a dead one is replaced.


## Flags

Every serve flag defaults to the production value, so supervisor runs it with none:

| Flag | Default |
|---|---|
| `--listen=` | `127.0.0.1:3300` |
| `--upstream-http=` | `127.0.0.1:3301` |
| `--upstream-https=` | `127.0.0.1:3302` |
| `--socket=` | `/run/proxority/proxority.sock` |
| `--pool-dir=` | `/etc/php/8.5/fpm/pool.d` |
| `--refresh-command=` | `php /var/www/html/system/artisan rsx:proxority:routes --json`, run in `/var/www/html` (a JSON argv array, or words split on whitespace; never a shell) |
| `--refresh-interval=` | `60` (seconds) |

An unknown flag refuses to start. `--listen=127.0.0.1:0` binds a free port (status shows the
one bound).

**Test seams**, never set in production: `--_connect-timeout-ms=` and
`--_response-timeout-ms=` shorten the two timeouts. The test suite starts a real proxy on a
free port against scratch pool files, a scratch socket, a fake upstream that holds each request
until the test releases it, and a fake refresh command with ok / fail / bad-JSON / maintenance
/ hang modes - so every ordering is decided by the proxy, never by timing.


## Supervision

Supervisor program `proxority`
(`system/app/RSpade/resource/docker/supervisor/conf.d/proxority.conf`):
`command=bash /opt/rspade/proxority/proxority-run.sh`. The run script finds node, then
`exec`s it, so the supervised pid is the proxy and signals reach it directly. It starts
after nginx and stops before it, so a draining proxy still has an upstream.


## Future issues

- **Loopback-origin requests skip the queue and are not counted busy** (owner's choice,
  2026-11-03). A request from the box itself - `rsx:debug`, server-side rendering, a test
  harness; never a companion call, which is scheduled - is dispatched at once and never appears in the busy count, so
  loopback traffic on top of a full pool can OVER-COMMIT php-fpm: Proxority believes workers
  are free that are in fact serving loopback requests, and dispatches more than the pool has.
  The excess waits in php-fpm's own listen backlog, unscheduled. To be addressed later.
- **An `ondemand` php-fpm pool runs fewer than N workers for a burst.** The capacity model
  assumes `pm.max_children` workers ready to serve. In `pm = ondemand` php-fpm forks at most one
  child per (edge-triggered) readiness event on its socket, so N requests dispatched together
  can find fewer than N children; the excess waits in php-fpm's listen backlog until a child
  frees or another connection arrives. Short requests hide it; behind long ones (a run of media
  downloads) a request Proxority counts as running waits as long as they take. A pool with N
  workers always running (`pm = static`, or `dynamic` with `pm.start_servers = pm.max_children`)
  makes the model exact; the image's pool stays ondemand, which frees an idle pool's memory. The
  live-stack tests therefore never hold a worker until the test releases it. Details:
  `system/app/RSpade/tests/proxority/issues_encountered.md`.
- **Unthrottled requests count against the caps, and busy may exceed N.** They are never held,
  so a burst of them pushes busy past the pool size, and the per-IP, media and robot counts
  they add hold back queued requests of the same address or class until they finish.
- **The open-source release is later.** Today Proxority is bespoke to the RSpade container and
  lives in the framework tree (as part of the container image's sources); packaging it as a
  project of its own is deferred.
- **Constants that may become configuration**: the 10 MB response buffer and the robot
  user-agent list are hard-coded.
- **The connect timeout has no automated test**: there is no portable way to make a loopback
  connect hang. Its timer is the same code path as the response-head timeout, which is tested.
