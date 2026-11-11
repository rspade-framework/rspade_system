# companions - test catalog

The `php/` tests sign as the fixture companions in `php/companion_fixture*/`, whose endpoints are
`Companion_Fixture_Endpoints`. They enter the manifest only under `rsx:test`. `http/` runs against
the live dev server and the template's `hello` companion.

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| CALL-VALID | a signed call reaches its endpoint headless | php | valid signature | 200, params delivered, site 1, session null | implemented | 2026-11-02 |
| CALL-204 | a null answer | php | endpoint returning null | 204 | implemented | 2026-11-02 |
| CALL-CONTEXT-ENDS | the request's companion ends with it | php | after a call | current_name() null | implemented | 2026-11-02 |
| CALL-STATUS-CALLABLE | starting, paused, stopping may call | php | record status | 200 | implemented | 2026-11-02 |
| CALL-CODED | a coded failure keeps its status | php | abort(404) | 404 not_found | implemented | 2026-11-02 |
| CALL-500 | a thrown endpoint is JSON with no detail | php | throw | 500 internal_error | implemented | 2026-11-02 |
| SIG-WRONG / SIG-MISSING / SIG-KEY | a bad, absent or wrongly keyed signature | php | header | 401 invalid_signature | implemented | 2026-11-02 |
| SIG-COVERS | body, site, contract, timestamp, nonce are all signed | php | tampered after signing | 401 invalid_signature | implemented | 2026-11-02 |
| WINDOW | outside +-60 s refused, inside passes | php | offsets | 401 stale_timestamp / 200 | implemented | 2026-11-02 |
| NONCE-REPLAY | a nonce is single use | php | same nonce twice | 401 replayed_nonce | implemented | 2026-11-02 |
| NONCE-UNBURNT | a refused request writes no nonce | php | bad signature, then good | 200 | implemented | 2026-11-02 |
| LIVE-* | no record, an ended status, another lifetime | php | record | 401 instance_not_live | implemented | 2026-11-02 |
| CONTRACT | a stale contract key | php | another key | 409 contract_mismatch | implemented | 2026-11-02 |
| UNAVAILABLE | Redis down fails closed | php | maintenance forced | 503 unavailable | implemented | 2026-11-02 |
| SITE-* | multi needs a site, runs in it; single refuses another; 0 and disabled refused | php | header, rsx.tenancy | 422 / 200 / 403 | implemented | 2026-11-02 |
| REALM-OTHER | a companion cannot reach another's endpoint | php | URL or name of the other | 401 | implemented | 2026-11-02 |
| REALM-404 | an unknown endpoint is a 404 only after authentication | php | signed, unsigned | 404, 401 | implemented | 2026-11-02 |
| REALM-SESSION | a CLI staff identity is not the writer | php | __acting_as_user | stamped as the companion | implemented | 2026-11-02 |
| REALM-SURFACE | the surface is its own kind and realm | php | Auth_Gates | staff and portal denied | implemented | 2026-11-02 |
| PATH-* | POST only, bare URI, the whole namespace is JSON | php | GET, ?x=1, short paths | 405, 400, 404 | implemented | 2026-11-02 |
| BODY-JSON | a non-JSON body | php | raw body | 400 invalid_json | implemented | 2026-11-02 |
| ACTOR-STAMP | a write is stamped and revisioned as the companion | php | fixture model | Companion:<name>, source companion, endpoint label | implemented | 2026-11-02 |
| ACTOR-ONE-ROW / ACTOR-LAZY | one row per name, none for a read | php | writes, a read | 1, 0 | implemented | 2026-11-02 |
| TASK-DISPATCHER | a #[Run_As_Dispatcher] task does not run for a companion | php | Task::internal() | refused | implemented | 2026-11-02 |
| LOG | a call is logged and counted, bodies never; a refused call is not counted | php | marker body | line, counter | implemented | 2026-11-02 |
| DAEMON-READY-* | ready, a reason, a throw, an unready install, an undeclared name | php | signed daemon call | 200 with ready false | implemented | 2026-11-02 |
| DAEMON-KEY | the daemon key is required and opens no endpoint | php | other keys | 401 | implemented | 2026-11-02 |
| DAEMON-REPLAY | the daemon's nonce is single use | php | same nonce | 401 replayed_nonce | implemented | 2026-11-02 |
| DAEMON-EVENT | event needs a name, answers 204 | php | bodies | 400, 204 | implemented | 2026-11-02 |
| INSTALL-READY | the migrated baseline is ready | php | Rsx_Install_State | null | implemented | 2026-11-02 |
| MANIFEST-DECL | the declaration reads off the class; names validated | php | fixture, bad names | row, FATAL | implemented | 2026-11-02 |
| MANIFEST-SERVICE | a companion is a service, so #[Task] works on it | php | fixture | subclass | implemented | 2026-11-02 |
| MANIFEST-FATALS | every realm FATAL | php | synthetic rows | RuntimeException naming it | implemented | 2026-11-02 |
| AUTH-SENTINEL | the gate resolves in the companion realm alone | php | Auth validate | staff, portal UNKNOWN CHECK | implemented | 2026-11-02 |
| AUTH-CONFLICT | a mixed kind is refused by Auth too | php | synthetic surface | COMPANION CONFLICT | implemented | 2026-11-02 |
| AUTH-ROW | every endpoint has a surface | php | row_surface_violations | none, then NO SURFACE | implemented | 2026-11-02 |
| HASH-CONTRACT | deterministic, follows the endpoints | php | assemble twice | equal, differs | implemented | 2026-11-02 |
| HASH-SOURCE | content and relative paths only | php | temp tree | stable across move and mtime | implemented | 2026-11-02 |
| DESCRIPTOR / CLIENT | the generated shapes | php | section row | fields, methods | implemented | 2026-11-02 |
| WIRE-* | the literals Node mirrors | php | keys, canonical, Redis keys | documented values | implemented | 2026-11-02 |
| HTTP-COOKIE | a signed-in browser's cookie is ignored over php-fpm | http | real login cookie | signed_in false, no row, no Set-Cookie | implemented | 2026-11-02 |
| HTTP-VERIFIER | the verifier's answers survive nginx | http | hand-signed curl | status and codes | implemented | 2026-11-02 |
| SEAL-LIST | build/companions is in the seal's list | php | Rsx_Prod_Seal | asset entries | planned | 2026-11-02 |
| NODE-PARITY | Node derives the same keys, canonical strings, scope token and source hash | php | wire_vectors.js | equal to PHP | implemented | 2026-11-02 |
| NODE-BACKOFF / NODE-RESP | the backoff curve and the RESP codec | php | wire_vectors.js | documented values | implemented | 2026-11-02 |
| D-START | a started companion has its record and environment; no APP_KEY, secret removed by the SDK | php | scratch daemon, sdk child | running, record, env | implemented | 2026-11-02 |
| D-DESIRED-STOP | nothing runs without a flag; a stop is graceful and recorded | php | flag on/off | stopped, history | implemented | 2026-11-02 |
| D-GRACE-KILL | the grace ends in SIGKILL | php | SIGTERM ignored, grace 1 | killed | implemented | 2026-11-02 |
| D-GROUP | an exit takes the whole process group | php | grandchild ignoring SIGTERM | grandchild gone | implemented | 2026-11-02 |
| D-REAP-BACKOFF | no zombies, backoff cannot storm, crashes fire companion.error | php | exit 1 loop | >= 5 s for 3 restarts, no Z child | implemented | 2026-11-02 |
| D-WAITING | waiting never counts | php | ready false, then true | restart_count 0 | implemented | 2026-11-02 |
| D-MAINTENANCE | overlay and framework flag hold down, desired untouched | php | flag files | stopped, desired running | implemented | 2026-11-02 |
| D-CONTROL | pause/resume reach the SDK; an operator stop is held until start | php | socket control | paused, held | implemented | 2026-11-02 |
| D-PAUSE-NON-SDK | pause is refused for a child with no SDK | php | socket control | error | implemented | 2026-11-02 |
| D-CONTRACT | exit 75 with a moved contract restarts at once, uncounted | php | descriptor moved, exit 75 | new instance, streak 0 | implemented | 2026-11-02 |
| D-ORPHANS | daemon SIGKILL: SDK child exits on EOF, a non-SDK child is swept by the next daemon | php | kill -9 | both gone | implemented | 2026-11-02 |
| D-FULL-PIPE | a stalled log writer never blocks a child | php | log file is an unread FIFO | child writes > 48 MiB | implemented | 2026-11-02 |
| D-ROTATE | logs rotate by size and compress by age | php | max_bytes 1 MiB, days_uncompressed 0 | .log.3, .gz | implemented | 2026-11-02 |
| D-SOCKET | ping is open, everything else needs the daemon key | php | raw frames | hello_required, invalid_hello | implemented | 2026-11-02 |
| D-EPOCH | records are republished after Redis loses them | php | DEL epoch, live, record | record back | implemented | 2026-11-02 |
| D-SOURCE | a source edit restarts it in development | php | edit a file | new instance | implemented | 2026-11-02 |
| D-POLICY-NEVER | restart_policy never leaves it ended | php | exit 0 | exited, 0 restarts | implemented | 2026-11-02 |
| D-ELECTION | single_instance is elected through rsx-lockd and fails over | php | two daemons, kill -9 the winner | other runs | implemented | 2026-11-02 |
| D-SUPERVISOR | supervisorctl stop waits out the grace; the shipped program's shape | php | scratch supervisord, 3 s stop | >= 3 s, child gone | implemented | 2026-11-02 |
| D-PAUSE-POLICY | the build marker pauses a `pause` companion | php | marker file | paused, resumed | planned | 2026-11-02 |
| D-BUILD-MARKER | a build is in progress only while the marker's `<pid> <start time>` is still the builder (`Companion_Build_Marker_Test`) | php | scratch markers: the live builder; its pid with another start time; a dead pid; a bare pid; none | true; false; false; false; false | implemented | 2026-11-10 |
| PROBE-RESERVED | the SDK's probe name cannot be declared, and the SDK probes that name | php | endpoint named rsx_sdk_contract_probe | FATAL; SDK constant equal | implemented | 2026-11-02 |
| PROBE-NOT-ERROR | the probe's authenticated 404 is logged with no code and counted with no error; another unknown endpoint still is one | php | signed probe | 404, code null, calls +1, errors +0 | implemented | 2026-11-02 |
| EVENT-PAYLOAD | companion.error reaches a handler with the six keys, as the companion, on the install site; severity and context default | php | event verb, handler stand-in | name, instance_id, code, message, severity, context | implemented | 2026-11-02 |
| GATE-DENY | with no handler nothing is viewable or controllable, and the topics refuse | php | no handlers | false, null, AjaxUnauthorizedException | implemented | 2026-11-02 |
| GATE-DEVELOPER | a developer passes every gate; {} needs every companion viewable; output is per companion | php | user 1 | true | implemented | 2026-11-02 |
| GATE-STAFF-ONLY | a portal request is refused, developer or handler | php | portal request | false | implemented | 2026-11-02 |
| GATE-HANDLERS | handlers decide per companion and per action; control needs view; unknown action throws | php | handler stand-ins | per name, per verb | implemented | 2026-11-02 |
| FACADE-STATUS | a status is composed from the summary and the instance record; a stale heartbeat is flagged; a daemon past its own heartbeat reports nothing; the host running the instance is the one shown | php | hand-written summaries and records | fields, unknown, host | implemented | 2026-11-02 |
| FACADE-INSTANCES | live records and history, ms as ISO, state decoded | php | record, history | shapes | implemented | 2026-11-02 |
| FACADE-OUTPUT | an exclusive opaque cursor, stream filter, paging, invalid cursor or stream refused | php | stream entries | lines, last_id, more | implemented | 2026-11-02 |
| FACADE-TRIMMED | a reader behind a trimmed stream is told; one that kept up is not | php | XTRIM | trimmed true / false | implemented | 2026-11-02 |
| FACADE-STATS | counters summed per endpoint over the minutes, average latency | php | two minutes | calls, errors, avg_ms | implemented | 2026-11-02 |
| HEALTH-ROWS | OK / INFO / WARN / FAIL from desired against actual; a row per declared companion | php | synthetic statuses | documented statuses | implemented | 2026-11-02 |
| REALTIME-FRAMES | the daemon publishes one frame per watching site in the relay's format, none when nobody watches | php | registry members, node | rsx_rt:<site> frames | implemented | 2026-11-02 |
| CONSOLE-LIVE | Companion_Output shows the stream and appends on a Companion_Output_Topic frame | playwright | the template's hello companion | lines appended | planned | 2026-11-02 |
| CMD-* | the six rsx:companion_apps commands against a scratch daemon | cli | flags, socket | output, flags | planned | 2026-11-02 |
