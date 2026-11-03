# Concern: proxority

## Domain overview & applicability

Proxority is the fair-queueing proxy between the public nginx and php-fpm in the RSpade
container. Per php-fpm pool (web, ajax) it keeps a ledger sized from `pm.max_children` and
decides which pending request runs next: a per-IP cap of N-1, a media cap of N/2, a robot cap
of N/5, priority requests ahead of the queue (never past the per-IP cap), round robin across
client addresses for the rest, loopback requests never queued or counted, unthrottled ones
never queued but counted. It refreshes the application's routing directives from a command on
an interval, and answers status and its event ring over a unix socket.

Applies wherever the proxy is deployed (the RSpade container); outside it nothing here runs.

## Source files

- `bin/proxority/proxority.js` - the server (request path, hop-by-hop header filtering, the two timeouts, backpressure,
  status socket) and the CLI client (`status`, `log`, `log --follow`)
- `bin/proxority/lib/scheduler.js` - the pure ledger: caps, queue, round robin, priority
- `bin/proxority/lib/classify.js` - pool, client address, loopback, robot, media, priority (the
  `/_ajax/_priority` suffix, revalidations), pattern syntax
- `bin/proxority/lib/whitelist.js` - the refresh command and its outcomes
- `bin/proxority/lib/event_log.js` - the 1000-event ring and its followers
- `bin/proxority/lib/pool_capacity.js` - `pm.max_children` from the pool files
- `bin/proxority/proxority-run.sh` - the supervisor entry

## Testable surface

- `php/Proxority_Unit_Test`: runs `resource/unit_tests.js`, the node unit tests of the four pure
  modules (scheduler, classifier, event ring, pool-file parser) and of the request-header filter
  `forwarded_request_headers()` exported by `proxority.js`.
- `php/Proxority_Scheduling_Test`, `Proxority_Proxying_Test`, `Proxority_Control_Test`: a real
  proxy started on a free port against a scratch directory - scratch pool files, a scratch status
  socket, `resource/fake_upstream.js` standing in for both loopback nginx flavours (requests are
  held until the test releases them, so every ordering is decided by the proxy, never by timing;
  scripted `reply=` answers - 304, 206, repeated Set-Cookie - exercise header pass-through, and it
  counts the TCP connections it accepted so upstream keep-alive is observable),
  and `resource/fake_refresh.js` standing in for `rsx:proxority:routes` (ok, fail, bad JSON,
  maintenance, hang). Shared harness: `php/Proxority_Test_Abstract`. Needs node only; no database,
  no Redis, and the supervised proxy is never touched.
- `php/Proxority_Live_Stack_Test`: the LIVE request path - the box's real nginx (public :80/:8000,
  loopback :3301/:3302), the supervised proxy on 127.0.0.1:3300 and the real php-fpm pools. PHP's
  view of a request comes from the framework's pre-boot probe (`bootstrap/rsx_test_probe.php`,
  `/_rsx_test_probe/<token>/<label>`), which answers only in development and only while this test
  process owns a marker for the token in `tmp/test-probes`; it is reached through the same `@php`
  location as every page, since nginx refuses every `.php` URL. Curl clients leave from the box's
  own non-loopback address (`--interface`) or from loopback with an X-Forwarded-For (a trusted
  proxy). Runs wherever nginx sends PHP-bound requests to the supervised proxy - an RSpade
  container, and the docker test workers, whose image is the development image with the proxy in
  it - and SKIPS wherever the proxy's status socket (`/run/proxority/proxority.sock`) does not
  answer. The flood test waits for the web pool to be idle before it starts.
- **No live test holds a php-fpm worker until the test releases it.** The pools are
  `pm = ondemand`, which can have fewer children started than `pm.max_children` when a burst is
  dispatched; a request waiting in php-fpm's backlog behind a worker the test never frees would
  wait for the proxy's 360 s bound (`issues_encountered.md`). A probe asked to `hold` answers by
  itself after a fixed two seconds, so every request completes, and scheduling is asserted from
  the ORDER of the proxy's event log, never from elapsed time or a momentary busy count.

Node scripts live in `resource/`, which the manifest ignores (a scanned `.js` under `tests/` would be
parsed as a bundle file).
