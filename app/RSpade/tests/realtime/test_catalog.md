# Test Catalog: realtime

NOTE: This concern's PHP suite predates the catalog. Rows below cover the
realtime_touch() METHOD-rung decoupling (Document Pipeline epic, Batch 1). The
remaining realtime tests (token, emissions buffer/flush/hook, attribute touch,
bulk emission/frame, manual emit, emitter discovery/dispatch/engine/constraint)
are implemented but not yet back-filled here.

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| MTOUCH-01 | Touch-only method model (no $realtime): save walks realtime_touch() cascade | php | save child with parent_id | parent pending, child ABSENT | implemented | 2026-07-16 |
| MTOUCH-02 | Bulk builder update hydrates the method surface and walks the cascade | php | ::where()->update() | parent pending, child ABSENT | implemented | 2026-07-16 |
| MTOUCH-03 | Bulk builder delete hydrates the method surface and walks the cascade | php | ::where()->delete() | parent pending, child ABSENT | implemented | 2026-07-16 |
| MTOUCH-04 | Own frame still requires $realtime across save + bulk update + bulk delete | php | all three flows | child key never pending | implemented | 2026-07-16 |
| MTOUCH-05 | Manual realtime_emit() publishes own frame AND walks cascade (request_emit semantics) | php | $child->realtime_emit() | both child and parent pending | implemented | 2026-07-16 |

## Session / User Refresh Push (control plane)

Targeted refresh control frames (Realtime_Session_Refresh_Test, Realtime_User_Refresh_Test).
The confirmed-different gating INSIDE the Session/Portal_Session web mutation branches is
web-only (the CLI test runner cannot reach those branches - they short-circuit to CLI
static overrides), so it is proven by live verification (rsx:debug + a direct-WS E2E +
Redis MONITOR: single push -> 1 frame, transaction dedup -> 1, rollback -> 0, realm
disambiguation) rather than a PHP unit test. The PHP tests below cover the transactional
plumbing + the User_Model/ACL dirty-field matrix, which ARE reachable in CLI.

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| SREFRESH-01 | Control frame flushes immediately with no transaction; session frame shape (kind/realm/session_id) | php | queue_session_refresh | 1 captured, correct shape | implemented | 2026-07-24 |
| SREFRESH-02 | User frame shape (kind/realm=staff/site_id/user_id) | php | queue_user_refresh | 1 captured, correct shape | implemented | 2026-07-24 |
| SREFRESH-03 | Control frame deferred until commit; discarded on rollback | php | queue in txn commit/rollback | flush on commit, none on rollback | implemented | 2026-07-24 |
| SREFRESH-04 | Dedup: identical session pushes collapse; distinct sessions / same-id-diff-realm stay distinct | php | pairs of queues | 1 / 2 / 2 | implemented | 2026-07-24 |
| SREFRESH-05 | Non-positive session/user ids are ignored | php | id 0 | 0 captured | implemented | 2026-07-24 |
| SREFRESH-06 | Web context stages control outbox then transmits; request-wide dedup | php | force web context | staged then drained; 1 per session | implemented | 2026-07-24 |
| SREFRESH-07 | push_* is a no-op when realtime disabled; queues when enabled | php | toggle config | 0 / 1 captured | implemented | 2026-07-24 |
| UREFRESH-01 | Each watched field change (first/last name, role_id, is_enabled) pushes user_refresh | php | User_Model save | 1 frame (site,user) | implemented | 2026-07-24 |
| UREFRESH-02 | Soft-delete pushes; non-watched (phone) change and no-change save are silent | php | delete / phone / no-op save | 1 / 0 / 0 | implemented | 2026-07-24 |
| UREFRESH-03 | ACL grant/deny/remove-existing push; remove-absent is silent | php | User_Permission_Model ops | 1/1/1/0 | implemented | 2026-07-24 |
| SREFRESH-E2E | Realm-scoped delivery through the real relay: session/user push reaches only the matching connection (staff vs portal same session-id disambiguated) | manual | direct WS client + tinker push | only target gets {type:'refresh'} | verified (live) | 2026-07-24 |
| SREFRESH-NODE | Node route_control + client refresh handler + anchor gate (session_hash) | playwright/manual | rsx:debug eval | anchor engages only with session_hash; deduped reload | verified (live) | 2026-07-24 |
| HARNESS-01 | rsx:debug harness client connect: scheme-follows-page derivation (ws://localhost/ws) completes token-mint -> handshake -> auth to 'connected' through the nginx :80 /ws proxy | http | http/realtime_debug_harness_connect.sh | state connected, url ws://localhost/ws | implemented | 2026-07-24 |

## Relay pre-auth DoS hardening (UC-108 / audit F-003)

A malformed websocket token signature made crypto.timingSafeEqual THROW RangeError on
unequal buffer lengths (it does not return false); uncaught in the ws message handler, it
killed the single relay process for every connected user - reachable pre-auth via
handle_auth()/handle_subscribe(). Fix: shape-guard the signature (64 lowercase hex) plus a
try/catch so validate_token can only return a payload or null, and drop the JSON literal
null frame (a second msg.type TypeError kill path). Tested against the relay's exported
validators; require()'ing the module runs no bootstrap (main() is require.main-gated), so the
test never touches the live supervised relay.

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| UC-108 | validate_token never throws + returns null on all hostile signature shapes (incl. 64-char non-hex audit input); valid token still returns payload; parse_json_frame drops non-JSON and literal null | http | http/realtime_relay_preauth_dos.sh (node harness over exported validators) | all assertions ok; RESULT PASS | implemented | 2026-07-28 |

## Delivery guarantee: subscribe-time seeding + the absent-baseline publish (2026-08-05)

The emitter engine used to treat a missing value-hash as "first seed" and swallow the
publish, on the assumption that the subscriber's resync had just delivered current state.
It had not: emitters are write-kicked, so the first computation IS the first change - and
the hash gap re-arms after every maintenance window / framework update (redis restarts
empty) as well as after 24h of quiet. Fix: baselines are seeded at SUBSCRIBE time over a
new relay -> PHP notify channel, and at write time an absent baseline PUBLISHES.

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| SEED-01 | seed_subscriptions_engine stores a baseline hash and publishes nothing | php | one emitter-served entry | seeded 1, 0 publishes, 1 rsx_rt:em key | implemented | 2026-08-05 |
| SEED-02 | Seed engine and run loop derive the SAME identity (an unchanged run after a seed is silent) | php | seed then run_emitters_engine | ran 1, published 0 | implemented | 2026-08-05 |
| SEED-03 | A change after seeding publishes exactly one frame with the filter payload | php | seed 'before', run 'after' | 1 publish, data = filter | implemented | 2026-08-05 |
| SEED-04 | Entries no emitter serves are skipped (relay reports ALL new members; PHP filters) | php | private topic + foreign-model Model_Changed_Topic entry | entries 0, seeded 0, no key | implemented | 2026-08-05 |
| SEED-05 | Reseeding is idempotent and refreshes the TTL (unsubscribe -> resubscribe) | php | seed twice | 1 key, live TTL, 0 publishes | implemented | 2026-08-05 |
| BELT-01 | Absent baseline PUBLISHES at write time, stores the hash on the same pass, and the next identical run settles | php | registry entry, no hash | published 1 then 0 | implemented | 2026-08-05 |
| NOTIFY-01 | Relay diff matrix: empty baseline = all new; identical rewrite = none; add reports only the addition; remove reports none; re-add reports again; array inputs behave like Sets | http | http/realtime_seed_notify_channel.sh (exported diff_new_members) | all ok, RESULT PASS | implemented | 2026-08-05 |
| NOTIFY-02 | HMAC wire parity: node sign_notify_body == php hash_hmac over the same body/key | http | same script, php verifier | MATCH | implemented | 2026-08-05 |
| NOTIFY-03 | subs_changed endpoint auth: valid+fresh 200; tampered body 403; correctly-signed stale ts 403; missing signature 403 | http | http/realtime_subs_changed_auth.sh (curl) | 200/403/403/403 | implemented | 2026-08-05 |
| SEED-E2E | Full channel through the real relay: browser subscribe -> registry diff -> notify POST -> filtered dispatch -> seed task row + baseline key; a later unchanged run is silent, a changed value publishes once, a wiped baseline publishes once (belt) | manual | rsx:debug watch + task/redis inspection + tinker probe | task completed {seeded:1}, key present, 0/1/1/0 publishes | verified (live) | 2026-08-05 |
| STALE-01 | Stale-reconnect reload: below threshold no reload; disconnect duration or drift gap above threshold schedules ONE reload with 500ms..10.5s jitter; drift tick records a big gap and ignores a small one; the existing refresh path still fires exactly one _do_reload | manual | rsx:debug --eval over the statics with _do_reload stubbed | below false, stale/gap true, delays in bounds, reloads 1 | verified (live) | 2026-08-05 |

## Token realm derivation (2026-08-09, portal-realm sweep)

`connection_token()` and `_current_site_id()` used to pick their facade with
`Portal_Session::is_logged_in()` - an IDENTITY test standing in for a REALM test. Every
UNAUTHENTICATED portal caller therefore took the staff branch, where
`Session::get_session_id()` MINTS a staff session and sets the `rsx` cookie on a portal
response: the exact failure that bricks a portal (staff cookie present -> staff CSRF token
demanded -> every portal Ajax call rejected). Both now fork on
`Rsx_Portal::is_portal_request()`, and `session_id` is gated on `has_session()` in both
realms so opening a socket never creates a session as a side effect.

Full finding: `docs.dev/audits/portal_realm_session_audit_2026_08_09.md` (D-1, D-2).

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| RTOKEN-R01 | An ANONYMOUS portal request mints a PORTAL-realm token - the case the old predicate got wrong | php | `__enter_portal(7)` with no portal user | `realm=portal`, `site_id=7`, `user_id=null` | implemented | 2026-08-09 |
| RTOKEN-R02 | Minting a token leaves BOTH facades exactly as it found them - in particular it never touches the staff one from a portal request | php | portal request | `has_session()` unchanged in both realms | implemented | 2026-08-09 |
| RTOKEN-R02b | The web-side MINT itself (the staff `Set-Cookie` on a portal response) - CLI cannot prove it: there `has_session()` is true as soon as a site is declared and `get_session_id()` returns 0 by design | http | `flash/http/flash_portal_login_cookie.sh` | no portal response ever emits a staff `Set-Cookie` | implemented (covered there) | 2026-08-09 |
| RTOKEN-R03 | A signed-in portal request still mints portal identity (regression guard on the fix) | php | `__enter_portal(7, 4242)` | `realm=portal`, `user_id=4242`, `site_id=7` | implemented | 2026-08-09 |
| RTOKEN-R04 | `_current_site_id()` agrees with `connection_token()` when anonymous on the portal - they must, or Node's site-match rejects every portal subscribe | php | both tokens in one portal request | identical `site_id`, both 7 | implemented | 2026-08-09 |

## One connection per tab (2026-08-24)

`_connect()` guarded on `Rsx_Realtime._ws`, which is only assignable AFTER the
connection-token round trip. Every subscriber arriving while that request was in flight
walked past the guard and opened a socket of its own - the exact shape of a page whose
components each subscribe in their own `on_create()`. One tab with one page open made the
relay report `Connections: 10, Subscriptions: 11`: ten authenticated sockets, nine orphaned
the moment the next overwrote `_ws`, every subscription riding the last. A second defect in
the same function let an orphan's `onclose` null out the LIVE socket's state and schedule a
reconnect. Fix: single-flight `_connect_promise` claimed synchronously before the first
await, plus an own-socket check at the head of every handler.

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| CONN-01 | Page shape: 10 distinct subscriptions in ONE tick open exactly ONE WebSocket, all established | http | http/realtime_single_connection.sh (A) | 1 socket, 10 watches, connected | implemented | 2026-08-24 |
| CONN-02 | Unit shape: 10 concurrent `_connect()` calls over a deliberately slowed token endpoint open exactly ONE socket and reach connected | http | same script (B) | 1 socket, connected | implemented | 2026-08-24 |
| CONN-03 | Own-socket guard: an orphaned socket's close leaves the live socket, `_ws` and the connection state untouched | http | same script (C) | live socket survives, state connected | implemented | 2026-08-24 |
| CONN-E2E | Relay-side proof through the real relay stats line: one tab, 10 same-tick subscriptions | manual | rsx:debug + /var/log/supervisor/realtime.log | before `Connections: 10, Subscriptions: 17`; after `Connections: 1, Subscriptions: 10` | verified (live) | 2026-08-24 |

## Connection lifecycle: idle path, batching, delayed offline, forced reconnect (2026-08-24)

Second pass over the client connection model, after the one-connection-per-tab fix above.
Two live idle-path defects: (A) `ws.close()` is ASYNCHRONOUS and `_ws` stays assigned
through CLOSING, so a `watch()` in that gap took `_connect()`'s "already connected" fast
path, had its subscribe dropped for a non-OPEN socket, and was then abandoned by an
`onclose` that trusted an intent flag set 10s earlier - a live entry in `_watches` with no
socket and nothing scheduled to make one, i.e. a page that silently stopped updating
forever. (B) an idle timer firing while the connection token was in flight found `_ws ===
null`, no-opped, and the socket then opened with nothing watching and was never closed.
Also in this pass: outgoing frames are batched (array-only wire format, cap 25 both ends),
the offline announcement is delayed past a 5s grace, and a suspend-sized clock gap or an
unanswered application-level liveness ping forces a disconnect/reconnect so the auth_ok
resync recovers.

Every row is proven in a real browser (rsx:debug) with the relay's own stats line as the
server-side witness. Rows IDLE-01/02 and OFFLINE-01/02 were confirmed NON-VACUOUS by
running them against the pre-fix client (see the Status column note).

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| IDLE-01 | A watch placed while the socket is in CLOSING ends CONNECTED with the subscription live and exactly one replacement socket | http | http/realtime_idle_lifecycle.sh (A) | connected, socket open, established, 1 resync, 1 socket | implemented (pre-fix: disconnected/never established) | 2026-08-24 |
| IDLE-02 | An idle timer that fires while a connect is in flight leaves no orphan socket - auth_ok re-schedules the idle close | http | same script (B) | no socket, terminal disconnected | implemented (pre-fix: socket left open forever) | 2026-08-24 |
| BATCH-01 | Ten messages produced in one tick leave as ONE frame | http | http/realtime_batched_frames.sh | 1 frame | implemented | 2026-08-24 |
| BATCH-02 | A burst of 60 chunks into ceil(60/25) = 3 frames, remainder included | http | same script | 3 frames | implemented | 2026-08-24 |
| BATCH-03 | 30 same-tick subscriptions coalesce (<= 20 frames in development, where Ajax batching is off) and all 30 reach the relay on ONE connection; no frame is refused | http | same script + relay stats line | `Connections: 1, Subscriptions: 30`, no protocol violation | implemented | 2026-08-24 |
| OFFLINE-01 | A brief drop announces 'reconnecting' immediately and never announces 'disconnected'; internal state goes false at once | http | http/realtime_offline_grace.sh | reconnecting first, no disconnected, ends connected | implemented (pre-fix: 'disconnected' announced instantly) | 2026-08-24 |
| OFFLINE-02 | A sustained outage announces 'disconnected' AFTER the grace window, not before, and recovers | http | same script | silent at 3s, announced at 7s, reconnects | implemented | 2026-08-24 |
| DRIFT-01 | A suspend-sized wall-clock gap observed while connected drops the socket synchronously, reconnects once, resyncs every watch, and never flashes 'disconnected' | http | http/realtime_drift_and_liveness.sh | 1 new socket, resync fired, connected | implemented | 2026-08-24 |
| DRIFT-02 | The 60-minute stale-CODE reload still fires - exactly once - through the drift-forced reconnect's auth_ok | http | same script (_do_reload stubbed) | 1 reload | implemented | 2026-08-24 |
| LIVE-01 | A quiet link sends an application ping and the relay answers with pong | http | same script | ping sent, pong observed | implemented | 2026-08-24 |
| LIVE-02 | A ping unanswered by the next tick forces a reconnect and the client recovers | http | same script | 1 new socket, connected | implemented | 2026-08-24 |
