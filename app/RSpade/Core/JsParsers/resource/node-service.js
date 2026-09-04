#!/usr/bin/env node

/**
 * THE node RPC service.
 *
 * ONE node process, ONE unix socket, serving every subsystem the PHP build needs a
 * JavaScript toolchain for: the parser, babel, terser/cssnano, the sourcemap-merging
 * concatenator, sass, the jqhtml template compiler, the code-quality sanitizer and the
 * code-quality linter.
 *
 * WHY ONE. Eight per-concern daemons each hand-rolled this same skeleton, and each cost a
 * spawn, a socket, a V8 heap and a thing to reap. On CLI they cost all of that for nothing:
 * Symfony's Process destructor kills a daemon when the artisan run exits, so one
 * `rsx:check` or `rsx:bundle:compile` used to start and immediately kill up to eight node
 * processes. Compiles are strictly sequential (there is only one compiling /
 * manifest-rebuilding process at a time), so eight processes bought no parallelism - only
 * eight idle heaps and eight ways to leave an orphan behind.
 *
 * PROTOCOL. Newline-delimited JSON, one request line in, one response line out:
 *
 *   {"id": N, "method": "<prefix>.<method>", ...payload}   ->  {"id": N, ...result}
 *
 * plus three top-level methods that belong to the service itself rather than a subsystem:
 *
 *   ping        -> {"result": "pong"}          liveness, and MUST stay cheap (see LAZY)
 *   introspect  -> {"result": {loaded, registered}}   which subsystems this process has
 *                                              actually loaded - the lazy-loading proof,
 *                                              and the hook a future health probe reads
 *   shutdown    -> {"result": "shutting down"} unlink the socket and exit
 *
 * LAZY LOADING IS MANDATORY. A subsystem module is require'd on FIRST USE and never before,
 * because the toolchains are enormous: babel, sass, terser, postcss and @jqhtml/parser each
 * cost real time and real memory to load. A ping must not load any of them, and a
 * concat-only session must never load sass. The dispatch table below therefore holds PATHS,
 * not modules.
 *
 * A module may be CommonJS or an ES module - every one is reached through dynamic import(),
 * which handles both. Its handler table is `module.exports` (CJS) or the DEFAULT export
 * (ESM); each handler takes the decoded request object and returns (or resolves to) the
 * object that is merged into the response beside its id.
 *
 * The registry lives in node-service-modules.json so that PHP reads the SAME list, and the
 * two can never drift. See Rsx_Node_Service.
 *
 * IDLE EXIT. The service exits cleanly after IDLE_EXIT_MS with nothing in flight and nothing
 * completed - see the watchdog at the bottom of this file for why that timer exists and why
 * it is not a timeout on anybody's work.
 *
 * @FILENAME-CONVENTION-EXCEPTION - Node.js RPC service entry point
 */

const fs = require('fs');
const net = require('net');
const path = require('path');
const { pathToFileURL } = require('url');

// Parse command line arguments
let socketPath = null;

// How long the service may sit COMPLETELY idle before exiting on its own. See the watchdog
// section at the bottom of this file.
let idleExitMs = 120000;

for (let i = 2; i < process.argv.length; i++) {
    const arg = process.argv[i];
    if (arg.startsWith('--socket=')) {
        socketPath = arg.substring('--socket='.length);
    }
    // TEST SEAM ONLY. Rsx_Node_Service never passes this; it exists so the lifecycle tests
    // can watch the idle exit happen in under a second instead of two minutes.
    if (arg.startsWith('--idle-exit-ms=')) {
        idleExitMs = parseInt(arg.substring('--idle-exit-ms='.length), 10);
    }
}

if (!socketPath) {
    console.error('Usage: node node-service.js --socket=/path/to/socket');
    process.exit(1);
}

// Remove socket if exists
if (fs.existsSync(socketPath)) {
    fs.unlinkSync(socketPath);
}

// Activity accounting for the idle watchdog at the bottom of this file. Declared here
// because the server handler below feeds them.
let openConnections = 0;
let inFlight = 0;
let lastActivity = Date.now();

// =============================================================================
// SUBSYSTEM REGISTRY (paths only - see LAZY LOADING above)
// =============================================================================

// The service is spawned with the framework's base path as its working directory, which is
// also where node_modules lives; the registry's paths are relative to it.
const basePath = process.cwd();

const REGISTRY_FILE = path.join(__dirname, 'node-service-modules.json');
const REGISTRY = JSON.parse(fs.readFileSync(REGISTRY_FILE, 'utf8'));
const MODULE_PATHS = REGISTRY.modules;

// Loaded handler tables, keyed by prefix. A module is loaded ONCE and stays loaded for the
// life of the process - which is the entire point of a daemon.
const loaded = {};

/**
 * The handler table for one subsystem, loading its module on first use.
 */
async function load_subsystem(prefix) {
    if (loaded[prefix]) {
        return loaded[prefix];
    }

    const relative = MODULE_PATHS[prefix];

    if (!relative) {
        throw new Error(
            `Unknown subsystem "${prefix}". Registered: ${Object.keys(MODULE_PATHS).sort().join(', ')}`
        );
    }

    const absolute = path.join(basePath, relative);

    if (!fs.existsSync(absolute)) {
        throw new Error(`Subsystem "${prefix}" module is missing from disk: ${absolute}`);
    }

    // import() serves a CommonJS module and an ES module alike; the CJS module.exports
    // arrives as the namespace's default.
    const namespace = await import(pathToFileURL(absolute).href);
    const handlers = namespace.default || namespace;

    loaded[prefix] = handlers;

    return handlers;
}

// =============================================================================
// DISPATCH
// =============================================================================

/**
 * Answer one request line. Returns the response line, newline included.
 */
async function handleRequest(data) {
    let request;

    try {
        request = JSON.parse(data);
    } catch (error) {
        return JSON.stringify({
            error: 'Invalid JSON request: ' + error.message
        }) + '\n';
    }

    try {
        const method = request.method;

        if (method === 'ping') {
            return JSON.stringify({ id: request.id, result: 'pong' }) + '\n';
        }

        if (method === 'shutdown') {
            return JSON.stringify({ id: request.id, result: 'shutting down' }) + '\n';
        }

        if (method === 'introspect') {
            return JSON.stringify({
                id: request.id,
                result: {
                    pid: process.pid,
                    socket: socketPath,
                    loaded: Object.keys(loaded).sort(),
                    registered: Object.keys(MODULE_PATHS).sort()
                }
            }) + '\n';
        }

        const separator = typeof method === 'string' ? method.indexOf('.') : -1;

        if (separator < 1) {
            return JSON.stringify({
                id: request.id,
                error: 'Unknown method: ' + method
                    + ' (a subsystem call is "<prefix>.<method>"; top-level methods are ping, introspect, shutdown)'
            }) + '\n';
        }

        const prefix = method.substring(0, separator);
        const name = method.substring(separator + 1);

        const handlers = await load_subsystem(prefix);
        const handler = handlers[name];

        if (typeof handler !== 'function') {
            return JSON.stringify({
                id: request.id,
                error: `Subsystem "${prefix}" has no method "${name}". `
                    + `It exposes: ${Object.keys(handlers).filter(k => typeof handlers[k] === 'function').sort().join(', ')}`
            }) + '\n';
        }

        const result = await handler(request);

        return JSON.stringify(Object.assign({ id: request.id }, result)) + '\n';
    } catch (error) {
        // A throw that escaped a handler is the service's own failure to report, not a
        // subsystem result - it goes back as a top-level error so the PHP client raises it.
        return JSON.stringify({
            id: request.id,
            error: error.message,
            stack: error.stack || null
        }) + '\n';
    }
}

// =============================================================================
// SERVER
// =============================================================================

const server = net.createServer((socket) => {
    let buffer = '';

    // A connected client is a client that is about to speak, or is speaking: it counts as
    // activity from the moment it arrives until it goes away.
    openConnections++;
    lastActivity = Date.now();

    socket.on('close', () => {
        openConnections--;
        lastActivity = Date.now();
    });

    socket.on('data', async (data) => {
        // Bytes arriving are activity even when they are only PART of a request line.
        lastActivity = Date.now();
        buffer += data.toString();

        let newlineIndex;
        while ((newlineIndex = buffer.indexOf('\n')) !== -1) {
            const line = buffer.substring(0, newlineIndex);
            buffer = buffer.substring(newlineIndex + 1);

            if (line.trim()) {
                // A request has been ACCEPTED: the service is working from this instant
                // until its response is written, however long that takes.
                inFlight++;
                lastActivity = Date.now();

                let response;
                try {
                    response = await handleRequest(line);
                } finally {
                    inFlight--;
                    lastActivity = Date.now();
                }

                socket.write(response);

                try {
                    const request = JSON.parse(line);
                    if (request.method === 'shutdown') {
                        socket.end();
                        server.close(() => {
                            if (fs.existsSync(socketPath)) {
                                fs.unlinkSync(socketPath);
                            }
                            process.exit(0);
                        });
                    }
                } catch (e) {
                    // Ignore parse errors for shutdown check
                }
            }
        }
    });

    socket.on('error', (err) => {
        console.error('Socket error:', err);
    });
});

server.listen(socketPath, () => {
    console.log('RSpade node service listening on ' + socketPath);
});

server.on('error', (err) => {
    console.error('Server error:', err);
    process.exit(1);
});

// =============================================================================
// IDLE WATCHDOG (orphan insurance - NOT a timeout on any request)
// =============================================================================
//
// THIS MUST NEVER FIRE DURING A LONG-RUNNING REQUEST - a three-minute sass compile with no
// other traffic is WORKING, not idle, and killing it would be exactly the failure the
// no-timeout mandate exists to prevent.
//
// That is why "idle" is measured from BOTH ends of a request: inFlight is raised the moment a
// request line is accepted and lowered only when its response has been produced, and
// lastActivity is refreshed at both points (and at every byte received, so a request line
// still arriving counts too). A connected client counts as well, whether or not it has
// finished speaking. The exit fires only when there is no open connection, nothing in flight
// AND nothing has completed for the whole window - so it bounds NOTHING that is making
// progress.
//
// What it does bound is an ORPHAN: this daemon's parent PHP process was SIGKILLed and will
// never call again (the documented orphan source - an interrupted build, a killed php-fpm
// worker). Its socket name is private to that dead parent, so nobody will ever speak to it.
// Expiry degrades to nothing: any LIVE parent transparently respawns a service on a fresh
// private socket at its next request (Rsx_Node_Service::request()), which costs one ~40ms
// spawn and is invisible to the caller.
// The watchdog ticks every 5s in production. The divisor keeps the TEST SEAM usable: with a
// sub-second --idle-exit-ms a 5s tick could never observe the window at all.
const IDLE_TICK_MS = Math.min(5000, Math.max(25, Math.floor(idleExitMs / 4)));

const idleWatchdog = setInterval(() => {
    if (inFlight !== 0 || openConnections !== 0) {
        return;
    }

    if (Date.now() - lastActivity <= idleExitMs) {
        return;
    }

    clearInterval(idleWatchdog);

    server.close(() => {
        if (fs.existsSync(socketPath)) {
            fs.unlinkSync(socketPath);
        }
        process.exit(0);
    });
}, IDLE_TICK_MS);

// The watchdog must never be the reason node stays alive.
if (typeof idleWatchdog.unref === 'function') {
    idleWatchdog.unref();
}

// Graceful shutdown handlers
process.on('SIGTERM', () => {
    server.close(() => {
        if (fs.existsSync(socketPath)) {
            fs.unlinkSync(socketPath);
        }
        process.exit(0);
    });
});

process.on('SIGINT', () => {
    server.close(() => {
        if (fs.existsSync(socketPath)) {
            fs.unlinkSync(socketPath);
        }
        process.exit(0);
    });
});
