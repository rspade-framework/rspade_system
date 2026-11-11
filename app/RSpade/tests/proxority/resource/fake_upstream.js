/**
 * A scriptable stand-in for the loopback nginx behind Proxority.
 *
 *     node fake_upstream.js <state_dir>
 *
 * Listens on two free ports, one per upstream flavour ("http" and "https"), and writes them to
 * <state_dir>/ports.json as {"http": N, "https": N}. Every request is recorded (which flavour
 * received it, method, path, raw headers) and answered as its query string says:
 *
 *     hold=<token>        answer nothing until /__release?token=<token>, then 200 "ok <token>"
 *     head=<token>        send the response head at once, hold the body until released
 *     size=<bytes>        a body of that many bytes, written with backpressure honoured;
 *                         progress is reported under stream=<token>
 *     reply=conditional   a resource with ETag "v1": 304 (no body, ETag and Cache-Control
 *                         kept) when If-None-Match is "v1" or If-Modified-Since is present,
 *                         else 200 "fresh"
 *     reply=range         the 26-byte body a..z, Accept-Ranges: bytes; a Range of
 *                         bytes=<first>-<last> is answered 206 with Content-Range and the slice
 *     reply=headers       302 with three Set-Cookie headers, Location, Vary, Cache-Control and
 *                         Content-Encoding: gzip over a gzipped "compressed" body
 *     (none)              200 "ok" at once
 *
 * Control endpoints, called by the test DIRECTLY (never through the proxy):
 *
 *     /__state            JSON: every request seen, every stream's progress, and how many
 *                         TCP connections each flavour accepted (sockets, control calls excluded)
 *     /__release?token=   release the held request(s) carrying that token
 *
 * Exits when its parent goes away, so a test killed outright leaves nothing behind.
 */

const fs = require('fs');
const http = require('http');
const path = require('path');
const zlib = require('zlib');

const state_dir = process.argv[2];

const CHUNK = Buffer.alloc(64 * 1024, 0x61);

const requests = [];
const holds = new Map();
const streams = {};
const sockets = { http: 0, https: 0 };
let next_id = 0;

const ALPHABET = 'abcdefghijklmnopqrstuvwxyz';

function json(res, object) {
    const body = JSON.stringify(object);
    res.writeHead(200, { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(body) });
    res.end(body);
}

function control(req, res, url) {
    if (url.pathname === '/__state') {
        json(res, { requests, streams, sockets });
        return;
    }
    if (url.pathname === '/__release') {
        const token = url.searchParams.get('token');
        const waiting = holds.get(token) || [];
        holds.delete(token);
        for (const release of waiting) release();
        json(res, { released: waiting.length });
        return;
    }
    res.writeHead(404);
    res.end();
}

function hold(token, release) {
    if (!holds.has(token)) holds.set(token, []);
    holds.get(token).push(release);
}

function stream_body(res, token, size) {
    const progress = { total: size, written: 0, waiting_drain: false, finished: false };
    streams[token] = progress;

    const write_more = () => {
        progress.waiting_drain = false;
        while (progress.written < size) {
            const chunk = size - progress.written >= CHUNK.length ? CHUNK : CHUNK.subarray(0, size - progress.written);
            progress.written += chunk.length;
            if (!res.write(chunk)) {
                progress.waiting_drain = true;
                res.once('drain', write_more);
                return;
            }
        }
        res.end(() => { progress.finished = true; });
    };
    write_more();
}

/** The scripted replies that exercise header pass-through (reply=<name>). */
function scripted(req, res, name) {
    if (name === 'conditional') {
        const revalidated = req.headers['if-none-match'] === '"v1"' || req.headers['if-modified-since'] !== undefined;
        const headers = { 'ETag': '"v1"', 'Cache-Control': 'no-cache', 'Last-Modified': 'Tue, 03 Nov 2026 10:00:00 GMT' };
        if (revalidated) {
            res.writeHead(304, headers);
            res.end();
        } else {
            res.writeHead(200, Object.assign({ 'Content-Type': 'text/plain', 'Content-Length': 5 }, headers));
            res.end('fresh');
        }
        return;
    }

    if (name === 'range') {
        const match = /^bytes=(\d+)-(\d+)$/.exec(req.headers['range'] || '');
        if (match) {
            const slice = ALPHABET.substring(Number(match[1]), Number(match[2]) + 1);
            res.writeHead(206, {
                'Content-Type': 'text/plain',
                'Accept-Ranges': 'bytes',
                'Content-Range': 'bytes ' + match[1] + '-' + match[2] + '/' + ALPHABET.length,
                'Content-Length': slice.length,
            });
            res.end(slice);
        } else {
            res.writeHead(200, { 'Content-Type': 'text/plain', 'Accept-Ranges': 'bytes', 'Content-Length': ALPHABET.length });
            res.end(ALPHABET);
        }
        return;
    }

    if (name === 'headers') {
        const body = zlib.gzipSync('compressed');
        res.writeHead(302, [
            'Set-Cookie', 'a=1; Path=/',
            'Set-Cookie', 'b=2; Path=/; HttpOnly',
            'Set-Cookie', 'c=3; Path=/; Secure',
            'Location', '/elsewhere?x=1',
            'Vary', 'Accept-Encoding, Cookie',
            'Cache-Control', 'private, max-age=60',
            'Content-Encoding', 'gzip',
            'Content-Type', 'text/plain',
            'Content-Length', String(body.length),
        ]);
        res.end(body);
        return;
    }

    res.writeHead(400);
    res.end('unknown reply ' + name);
}

function serve(flavour) {
    const server = http.createServer((req, res) => {
        const url = new URL(req.url, 'http://upstream');
        if (url.pathname.startsWith('/__')) {
            control(req, res, url);
            return;
        }

        const record = {
            id: ++next_id,
            upstream: flavour,
            method: req.method,
            path: url.pathname,
            url: req.url,
            raw_headers: req.rawHeaders,
            aborted: false,
            answered: false,
        };
        requests.push(record);
        res.on('close', () => { if (!res.writableFinished) record.aborted = true; });

        req.resume();

        const query = url.searchParams;
        const answer = (body) => {
            if (res.destroyed) return;
            record.answered = true;
            res.writeHead(200, { 'Content-Type': 'text/plain', 'Content-Length': Buffer.byteLength(body) });
            res.end(body);
        };

        if (query.has('reply')) {
            record.answered = true;
            scripted(req, res, query.get('reply'));
        } else if (query.has('size')) {
            res.writeHead(200, { 'Content-Type': 'application/octet-stream', 'Content-Length': Number(query.get('size')) });
            stream_body(res, query.get('stream') || 'stream', Number(query.get('size')));
        } else if (query.has('hold')) {
            hold(query.get('hold'), () => answer('ok ' + query.get('hold')));
        } else if (query.has('head')) {
            res.writeHead(200, { 'Content-Type': 'text/plain' });
            res.flushHeaders();
            hold(query.get('head'), () => { if (!res.destroyed) res.end('ok ' + query.get('head')); });
        } else {
            answer('ok');
        }
    });

    // A connection counts once its first request is a real one: the test's own control calls
    // (/__state, /__release) arrive on connections of their own and are not counted.
    server.on('connection', (socket) => {
        socket.once('data', (chunk) => {
            if (!/^[A-Z]+ \/__/.test(chunk.toString('latin1', 0, 32))) sockets[flavour]++;
        });
    });

    return server;
}

const servers = { http: serve('http'), https: serve('https') };
const ports = {};
let listening = 0;

for (const [flavour, server] of Object.entries(servers)) {
    server.listen(0, '127.0.0.1', () => {
        ports[flavour] = server.address().port;
        if (++listening === 2) {
            const file = path.join(state_dir, 'ports.json');
            fs.writeFileSync(file + '.tmp', JSON.stringify(ports));
            fs.renameSync(file + '.tmp', file);
        }
    });
}

// A poll for the parent's exit, so an orphan never outlives its test.
// The parent is gone when this process has been reparented - asked of our own ppid, never of a
// remembered pid, which the kernel may since have handed to another process.
const parent = process.ppid;
setInterval(() => {
    if (process.ppid !== parent) {
        process.exit(0);
    }
}, 1000);
