/**
 * Unit tests of Proxority's pure modules: the scheduler, the classifier, the event ring and
 * the pool-file parser.
 *
 *     node unit_tests.js
 *
 * Prints one "[OK] name" line per test and "[ERROR] name: reason" per failure, then a summary
 * line; exits 0 only when every test passed. Run by Proxority_Unit_Test.
 */

const assert = require('assert');
const path = require('path');

const lib = path.resolve(__dirname, '../../../resource/docker/proxority/lib');
const { Pool_Ledger, caps_for } = require(path.join(lib, 'scheduler.js'));
const classify = require(path.join(lib, 'classify.js'));
const { Event_Log, EVENT_LIMIT } = require(path.join(lib, 'event_log.js'));
const { parse_max_children } = require(path.join(lib, 'pool_capacity.js'));
const { forwarded_request_headers } = require(path.join(lib, '..', 'proxority.js'));

const tests = [];
function test(name, body) { tests.push({ name, body }); }

let next = 0;
function request(ip, extra = {}) {
    return Object.assign({ id: ++next, ip, media: false, robot: false, priority: false, unthrottled: false, loopback: false }, extra);
}
function ids(list) { return list.map((r) => r.id); }

// -----------------------------------------------------------------------------------------
// Scheduler
// -----------------------------------------------------------------------------------------

test('caps follow the pool size with a floor of one', () => {
    assert.deepStrictEqual(caps_for(1), { per_ip: 1, media: 1, robot: 1 });
    assert.deepStrictEqual(caps_for(2), { per_ip: 1, media: 1, robot: 1 });
    assert.deepStrictEqual(caps_for(4), { per_ip: 3, media: 2, robot: 1 });
    assert.deepStrictEqual(caps_for(10), { per_ip: 9, media: 5, robot: 2 });
    assert.deepStrictEqual(caps_for(11), { per_ip: 10, media: 5, robot: 2 });
});

test('one address gets at most N-1 workers', () => {
    const ledger = new Pool_Ledger('web', 4);
    const a = [1, 2, 3, 4].map(() => request('a'));
    const started = a.flatMap((r) => ledger.submit(r));
    assert.deepStrictEqual(ids(started), ids(a.slice(0, 3)));
    assert.strictEqual(a[3]._state, 'queued');

    const b = request('b');
    assert.deepStrictEqual(ids(ledger.submit(b)), [b.id], 'another address takes the last worker');
    assert.deepStrictEqual(ledger.release(b), [], 'a\'s fourth still waits at its cap');
    assert.deepStrictEqual(ids(ledger.release(a[0])), [a[3].id]);
});

test('a pool of one serves one request at a time', () => {
    const ledger = new Pool_Ledger('web', 1);
    const first = request('a');
    const second = request('b');
    assert.strictEqual(ledger.submit(first).length, 1);
    assert.strictEqual(ledger.submit(second).length, 0);
    assert.deepStrictEqual(ids(ledger.release(first)), [second.id]);
});

test('round robin takes one request per address per turn', () => {
    const ledger = new Pool_Ledger('web', 1);
    const blocker = request('z');
    ledger.submit(blocker);

    const a = [request('a'), request('a'), request('a')];
    const b = [request('b'), request('b')];
    const c = [request('c')];
    for (const r of [...a, ...b, ...c]) ledger.submit(r);

    const order = [];
    let running = blocker;
    for (let i = 0; i < 6; i++) {
        const started = ledger.release(running);
        assert.strictEqual(started.length, 1);
        running = started[0];
        order.push(running.id);
    }
    assert.deepStrictEqual(order, [a[0].id, b[0].id, c[0].id, a[1].id, b[1].id, a[2].id]);
});

test('a new address joins the rotation at the end', () => {
    const ledger = new Pool_Ledger('web', 1);
    const blocker = request('z');
    ledger.submit(blocker);
    const a1 = request('a');
    const a2 = request('a');
    ledger.submit(a1);
    ledger.submit(a2);

    const first = ledger.release(blocker)[0];
    assert.strictEqual(first.id, a1.id);
    const b1 = request('b');
    ledger.submit(b1);
    assert.strictEqual(ledger.release(first)[0].id, b1.id, 'b had not had a turn yet');
});

test('priority jumps the queue', () => {
    const ledger = new Pool_Ledger('web', 1);
    const blocker = request('z');
    ledger.submit(blocker);
    const normal = request('a');
    const urgent = request('b', { priority: true });
    ledger.submit(normal);
    ledger.submit(urgent);
    assert.strictEqual(ledger.release(blocker)[0].id, urgent.id);
});

test('priority never exceeds the per-IP cap', () => {
    const ledger = new Pool_Ledger('web', 3);
    const z1 = request('z');
    const z2 = request('z');
    const x1 = request('x');
    ledger.submit(z1);
    ledger.submit(z2);
    ledger.submit(x1);

    const normal = request('a');
    const z_urgent = request('z', { priority: true });
    ledger.submit(normal);
    ledger.submit(z_urgent);

    assert.strictEqual(ledger.release(x1)[0].id, normal.id, 'z is at its cap of 2, so its priority request waits');
    assert.strictEqual(ledger.release(z1)[0].id, z_urgent.id);
});

test('media takes at most half the pool', () => {
    const ledger = new Pool_Ledger('web', 4);
    const m = [request('a', { media: true }), request('b', { media: true }), request('c', { media: true })];
    const started = m.flatMap((r) => ledger.submit(r));
    assert.deepStrictEqual(ids(started), ids(m.slice(0, 2)));

    const page = request('d');
    assert.deepStrictEqual(ids(ledger.submit(page)), [page.id], 'a page passes the waiting download');
    assert.strictEqual(ledger.snapshot().busy_by_class.media, 2);
    assert.deepStrictEqual(ids(ledger.release(m[0])), [m[2].id]);
});

test('a blocked request is skipped and the next one from that address runs', () => {
    const ledger = new Pool_Ledger('web', 4);
    ledger.submit(request('x', { media: true }));
    ledger.submit(request('y', { media: true }));
    const blocked = request('a', { media: true });
    const page = request('a');
    ledger.submit(blocked);
    assert.strictEqual(blocked._state, 'queued');
    assert.deepStrictEqual(ids(ledger.submit(page)), [page.id]);
});

test('robots take a fifth of the pool', () => {
    const ledger = new Pool_Ledger('web', 10);
    const robots = [1, 2, 3].map((n) => request('r' + n, { robot: true }));
    assert.strictEqual(robots.flatMap((r) => ledger.submit(r)).length, 2);
    assert.strictEqual(robots[2]._state, 'queued');
    assert.strictEqual(ledger.snapshot().busy_by_class.robot, 2);
});

test('a media robot counts against both caps', () => {
    const ledger = new Pool_Ledger('web', 4);
    ledger.submit(request('a', { robot: true, media: true }));
    const robot = request('b', { robot: true });
    const media = request('c', { media: true });
    assert.strictEqual(ledger.submit(robot).length, 0, 'robot cap 1 is full');
    assert.strictEqual(ledger.submit(media).length, 1, 'media cap 2 has room');
});

test('loopback is never queued and never counted', () => {
    const ledger = new Pool_Ledger('web', 1);
    const local = [1, 2, 3].map(() => request('127.0.0.1', { loopback: true }));
    assert.strictEqual(local.flatMap((r) => ledger.submit(r)).length, 3);
    const snap = ledger.snapshot();
    assert.strictEqual(snap.busy_total, 0);
    assert.strictEqual(snap.loopback_running, 3);

    const remote = request('a');
    assert.strictEqual(ledger.submit(remote).length, 1, 'loopback took no worker');
    ledger.release(local[0]);
    assert.strictEqual(ledger.snapshot().loopback_running, 2);
});

test('unthrottled runs at once and is counted busy', () => {
    const ledger = new Pool_Ledger('web', 1);
    ledger.submit(request('a'));
    const asset = request('b', { unthrottled: true });
    assert.strictEqual(ledger.submit(asset).length, 1);
    const snap = ledger.snapshot();
    assert.strictEqual(snap.busy_total, 2);
    assert.strictEqual(snap.busy_by_class.unthrottled, 1);

    const waiting = request('c');
    assert.strictEqual(ledger.submit(waiting).length, 0);
    assert.strictEqual(ledger.release(asset).length, 0, 'still at capacity');
});

test('a cancelled request leaves the queue and the rotation', () => {
    const ledger = new Pool_Ledger('web', 1);
    const blocker = request('z');
    ledger.submit(blocker);
    const a = request('a');
    const b = request('b');
    ledger.submit(a);
    ledger.submit(b);
    assert.strictEqual(ledger.cancel(a), true);
    assert.strictEqual(ledger.cancel(a), false);
    assert.strictEqual(ledger.snapshot().queued_total, 1);
    assert.strictEqual(ledger.release(blocker)[0].id, b.id);
});

test('releasing twice is a no-op', () => {
    const ledger = new Pool_Ledger('web', 2);
    const a = request('a');
    ledger.submit(a);
    ledger.release(a);
    ledger.release(a);
    assert.strictEqual(ledger.snapshot().busy_total, 0);
});

test('a larger capacity starts what now fits', () => {
    const ledger = new Pool_Ledger('web', 1);
    ledger.submit(request('a'));
    const b = request('b');
    ledger.submit(b);
    assert.deepStrictEqual(ids(ledger.set_capacity(2)), [b.id]);
});

test('the snapshot lists queued addresses, busiest first', () => {
    const ledger = new Pool_Ledger('web', 1);
    ledger.submit(request('z'));
    ledger.submit(request('a'));
    ledger.submit(request('b'));
    ledger.submit(request('b', { priority: true }));
    const snap = ledger.snapshot();
    assert.deepStrictEqual(snap.queued_by_ip, [{ ip: 'b', count: 2 }, { ip: 'a', count: 1 }]);
    assert.strictEqual(snap.queued_priority, 1);
});

// -----------------------------------------------------------------------------------------
// Classifier
// -----------------------------------------------------------------------------------------

test('the pool is chosen by raw path prefix', () => {
    assert.strictEqual(classify.pool_for_path('/_ajax/_batch'), 'ajax');
    assert.strictEqual(classify.pool_for_path('/_companion/x/y'), 'ajax');
    assert.strictEqual(classify.pool_for_path('/_ajaxy'), 'web');
    assert.strictEqual(classify.pool_for_path('/portal/_ajax/x'), 'web');
    assert.strictEqual(classify.pool_for_path('/'), 'web');
});

test('the client is X-Real-IP, else the socket address', () => {
    assert.strictEqual(classify.client_ip({ 'x-real-ip': ' 203.0.113.5 ' }, '127.0.0.1'), '203.0.113.5');
    assert.strictEqual(classify.client_ip({}, '::ffff:198.51.100.2'), '198.51.100.2');
    assert.strictEqual(classify.client_ip({ 'x-real-ip': '' }, '::1'), '::1');
});

test('loopback addresses', () => {
    for (const ip of ['127.0.0.1', '127.8.9.10', '::1', '::ffff:127.0.0.1']) assert.ok(classify.is_loopback(ip), ip);
    for (const ip of ['10.0.0.1', '128.0.0.1', '::2', '']) assert.ok(!classify.is_loopback(ip), ip);
});

test('robots by user agent', () => {
    for (const ua of [
        'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
        'Mozilla/5.0 (compatible; bingbot/2.0)',
        'facebookexternalhit/1.1',
        'Mozilla/5.0 (compatible; ClaudeBot/1.0)',
        'SomeCrawler/3.0',
        'example-spider',
        'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.0)',
    ]) assert.ok(classify.is_robot(ua), ua);
    assert.ok(!classify.is_robot('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120 Safari/537.36'));
    assert.ok(!classify.is_robot(undefined));
});

test('patterns: exact, :param, :param?, trailing *', () => {
    const set = new classify.Pattern_Set(['/exact', '/files/:key', '/thumb/:key/:size?', '/_compiled/*']);
    assert.ok(set.matches('/exact'));
    assert.ok(!set.matches('/exact/more'));
    assert.ok(set.matches('/files/abc'));
    assert.ok(!set.matches('/files/abc/def'));
    assert.ok(!set.matches('/files/'));
    assert.ok(set.matches('/thumb/abc'));
    assert.ok(set.matches('/thumb/abc/64'));
    assert.ok(!set.matches('/thumb/abc/64/x'));
    assert.ok(set.matches('/_compiled/app.js'));
    assert.ok(set.matches('/_compiled/a/b/c.css'));
    assert.ok(!set.matches('/_compiledx'));
    assert.strictEqual(set.count, 4);
});

test('regex characters in a pattern are literal', () => {
    const set = new classify.Pattern_Set(['/a.b/:id']);
    assert.ok(set.matches('/a.b/1'));
    assert.ok(!set.matches('/axb/1'));
});

test('malformed patterns are refused', () => {
    assert.throws(() => classify.compile_pattern('no-slash'));
    assert.throws(() => classify.compile_pattern('/a/*/b'));
    assert.throws(() => classify.compile_pattern('/a/:bad-name'));
    assert.throws(() => classify.compile_pattern('/a/:opt?/b'));
    assert.throws(() => classify.compile_whitelist({ unthrottled: [], priority: [] }));
    assert.throws(() => classify.compile_whitelist({ unthrottled: [1], priority: [], media: [] }));
    assert.throws(() => classify.compile_whitelist([]));
});

test('classify a request', () => {
    const whitelist = classify.compile_whitelist({
        unthrottled: ['/_compiled/*'],
        priority: ['/urgent/:id'],
        media: ['/download/:key'],
    });
    const of = (url, headers = {}) => classify.classify({ method: 'GET', url, headers, remote_address: '127.0.0.1' }, whitelist);

    const asset = of('/_compiled/app.js?v=1', { 'x-real-ip': '203.0.113.1' });
    assert.strictEqual(asset.path, '/_compiled/app.js');
    assert.ok(asset.unthrottled && !asset.loopback && asset.pool === 'web');
    assert.ok(of('/urgent/5').priority);
    assert.ok(of('/_ajax/_priority').priority);
    assert.ok(!of('/_ajax/_batch').priority);
    assert.ok(of('/download/abc?x=1').media);
    assert.ok(of('/upload', { 'content-type': 'multipart/form-data; boundary=x' }).media);
    assert.ok(!of('/upload', { 'content-type': 'application/json' }).media);
    assert.ok(of('/', {}).loopback, 'no X-Real-IP: the socket address');

    const empty = classify.classify({ url: '/_compiled/app.js', headers: {}, remote_address: '1.2.3.4' }, classify.empty_whitelist());
    assert.ok(!empty.unthrottled, 'before the first answer nothing is whitelisted');
});

test('the priority Ajax channel is matched as a path suffix', () => {
    const of = (url) => classify.classify({ method: 'POST', url, headers: {}, remote_address: '203.0.113.1' }, classify.empty_whitelist());
    assert.ok(of('/_ajax/_priority').priority);
    assert.strictEqual(of('/_ajax/_priority').pool, 'ajax');
    const portal = of('/_portal/_ajax/_priority');
    assert.ok(portal.priority, 'a portal under a path prefix');
    assert.strictEqual(portal.pool, 'web', 'the pool still follows nginx\'s prefix routing');
    assert.ok(of('/_portal/acme/_ajax/_priority?x=1').priority, 'a tenant placeholder in the prefix; the query is not the path');
    assert.ok(!of('/_ajax/_priority/x').priority);
    assert.ok(!of('/_portal/_ajax/_priority_not').priority);
    assert.ok(!of('/x_ajax/_priority').priority, 'a whole segment, not a substring');
});

test('a GET or HEAD revalidation is priority; nothing else is', () => {
    const whitelist = classify.compile_whitelist({ unthrottled: [], priority: [], media: ['/download/:key'] });
    const of = (method, url, headers) => classify.classify({ method, url, headers, remote_address: '203.0.113.1' }, whitelist);

    assert.ok(of('GET', '/page', { 'if-none-match': '"abc"' }).priority);
    assert.ok(of('GET', '/page', { 'if-modified-since': 'Tue, 03 Nov 2026 10:00:00 GMT' }).priority);
    assert.ok(of('HEAD', '/page', { 'if-none-match': '"abc"' }).priority);
    assert.ok(!of('GET', '/page', {}).priority);
    assert.ok(!of('POST', '/page', { 'if-none-match': '"abc"' }).priority, 'a header must not lift a write');
    assert.ok(!of('PUT', '/page', { 'if-modified-since': 'x' }).priority);
    assert.ok(!of('GET', '/page', { 'if-match': '"abc"' }).priority);
    assert.ok(!of('GET', '/page', { 'if-unmodified-since': 'x' }).priority);
    assert.ok(!of('GET', '/page', { 'if-range': '"abc"', 'range': 'bytes=0-9' }).priority);

    const download = of('GET', '/download/k1', { 'if-none-match': '"abc"' });
    assert.ok(download.priority && download.media, 'a media revalidation is priority and still media');
});

test('hop-by-hop request headers are dropped, everything else forwarded in order', () => {
    const forwarded = forwarded_request_headers([
        'Host', 'example.test',
        'Connection', 'close, X-Hop, Content-Length, Host',
        'X-Hop', 'gone',
        'Keep-Alive', 'timeout=5',
        'Proxy-Connection', 'keep-alive',
        'TE', 'trailers',
        'Trailer', 'X-T',
        'Upgrade', 'h2c',
        'X-Rsx-Scheme', 'https',
        'X-Forwarded-For', '203.0.113.9',
        'X-Real-IP', '203.0.113.9',
        'If-None-Match', '"e1"',
        'Range', 'bytes=0-9',
        'Cookie', 'a=1',
        'Authorization', 'Bearer x',
        'Content-Length', '3',
        'Transfer-Encoding', 'chunked',
    ]);
    assert.deepStrictEqual(forwarded, [
        'Host', 'example.test',
        'X-Forwarded-For', '203.0.113.9',
        'X-Real-IP', '203.0.113.9',
        'If-None-Match', '"e1"',
        'Range', 'bytes=0-9',
        'Cookie', 'a=1',
        'Authorization', 'Bearer x',
        'Content-Length', '3',
        'Transfer-Encoding', 'chunked',
    ], 'a Connection token never drops the framing headers or Host');
});

// -----------------------------------------------------------------------------------------
// Event ring and pool files
// -----------------------------------------------------------------------------------------

test('the event ring keeps the latest 1000', () => {
    assert.strictEqual(EVENT_LIMIT, 1000);
    const log = new Event_Log();
    for (let i = 0; i < 1500; i++) log.log('queued', 'event ' + i);
    const entries = log.entries();
    assert.strictEqual(entries.length, 1000);
    assert.strictEqual(entries[0].text, 'event 500');
    assert.strictEqual(entries[999].text, 'event 1499');
});

test('followers receive new events; traffic is not echoed', () => {
    const echoed = [];
    const log = new Event_Log({ echo: (line) => echoed.push(line) });
    const seen = [];
    const unfollow = log.follow((event) => seen.push(event.type));
    log.log('queued', 'x');
    log.log('timeout', 'y');
    unfollow();
    log.log('refresh_ok', 'z');
    assert.deepStrictEqual(seen, ['queued', 'timeout']);
    assert.strictEqual(echoed.length, 2);
    assert.ok(echoed[0].includes('[timeout] y'));
});

test('pm.max_children is read from a pool file', () => {
    assert.strictEqual(parse_max_children('[www]\n; pm.max_children = 99\npm = dynamic\npm.max_children = 7\n', 'www.conf'), 7);
    assert.throws(() => parse_max_children('[www]\npm = dynamic\n', 'www.conf'), /sets no pm.max_children/);
    assert.throws(() => parse_max_children('pm.max_children = 0\n', 'www.conf'), /at least 1/);
});

// -----------------------------------------------------------------------------------------

let failed = 0;
for (const { name, body } of tests) {
    try {
        body();
        process.stdout.write('[OK] ' + name + '\n');
    } catch (error) {
        failed++;
        process.stdout.write('[ERROR] ' + name + ': ' + error.message + '\n');
    }
}
process.stdout.write((tests.length - failed) + ' of ' + tests.length + ' passed\n');
process.exit(failed === 0 ? 0 : 1);
