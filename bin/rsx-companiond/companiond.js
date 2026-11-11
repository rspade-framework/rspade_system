#!/usr/bin/env node
/**
 * rsx-companiond - starts, stops, watches and reaps an application's companion processes.
 *
 *     node companiond.js run [--project-root=<dir>] [--build-root=<dir>] [--host=<name>]
 *
 * `run` is the only verb, and the three options exist to point a test's daemon at a scratch
 * tree. Under supervisor: companiond-run.sh. Everything an operator does goes through
 * `php artisan rsx:companion_apps:*`.
 *
 * It comes up with no database, no Redis and no application, and waits for them: on a container's
 * first boot supervisor starts it before any of those exist. See CLAUDE.md in this directory
 * for the map, and rsx:man companion_apps for the contract.
 */

const fs = require('fs');
const path = require('path');

const config_module = require('./lib/config.js');
const rsx_paths = require('../lib/rsx_paths.js');

const { App_Client } = require('./lib/app_client.js');
const children_file = require('./lib/children_file.js');
const { Companion } = require('./lib/companion.js');
const fingerprint = require('./lib/fingerprint.js');
const { Redis_Client } = require('./lib/redis_client.js');
const { Socket_Server } = require('./lib/socket_server.js');
const { Store } = require('./lib/store.js');

/** How often the flags, the build key and the Redis epoch are read. A poll interval. */
const TICK_MS = 1000;

/** A development tree is fingerprinted every this many ticks: a stat per file, no reads. */
const SCAN_EVERY_TICKS = 3;

/** Old log generations are retired about hourly, so a silent companion's are too. */
const LOG_SWEEP_EVERY_TICKS = 3600;

/** The daemon's own line in Redis, and how often it is refreshed: its heartbeat. */
const DAEMON_SUMMARY_MS = 30000;
const DAEMON_SUMMARY_FIELD = '_daemon';

/** Errors held for an application that is not answering. A memory bound: older ones drop. */
const MAX_QUEUED_EVENTS = 100;

class Daemon {
    constructor(config) {
        this.config = config;
        this.started_at = Date.now();
        this.companions = new Map();
        this.children = [];
        this.shutting_down = false;

        this.redis = new Redis_Client(config.redis);
        // The realtime relay's database, for its registry and channels; none while disabled.
        this.realtime_redis = config.realtime_enabled ? new Redis_Client(Object.assign({}, config.redis, { db: 0 })) : null;
        this.store = new Store(this.redis, config.prefix, config.host, this.realtime_redis);
        this.app = new App_Client(config.base_url, config.daemon_key);
        this.redis_ready = false;

        this.build_present = false;
        this.build_key = null;
        this.framework_maintenance = false;
        this.overlay_maintenance = false;
        this.build_marker_active = false;
        this.mode = config_module.current_mode();

        this.tick_count = 0;
        this.ticking = false;
        this.tick_again = false;
        this.summaries_moved = new Set();
        this.outputs_moved = new Set();
        this.last_daemon_summary = 0;
        this.ended_backlog = [];
        this.events = [];
        this.settle_waiters = [];

        this.base_env = config_module.child_base_env(config);

        this.server = new Socket_Server({
            path: config.socket_file,
            key: config.daemon_key,
            owner: config.identity,
            handle: (frame) => this.handle_verb(frame),
            ping: () => ({ pid: process.pid, host: config.host }),
        });
    }

    log(message) {
        const line = '[' + new Date().toISOString() + '] [companiond] ' + message + '\n';
        process.stdout.write(line);

        // Opened per line, so a rename by the framework's log rotation is simply followed.
        fs.promises.appendFile(this.config.daemon_log_file, line).catch(() => {});
    }

    host() { return this.config.host; }
    child_base_env() { return this.base_env; }
    is_development() { return this.mode === 'development'; }

    logs_dir(name) {
        return rsx_paths.companion_logs_dir(name);
    }

    maintenance_reason() {
        if (this.framework_maintenance) return 'the application is in maintenance mode';
        if (this.overlay_maintenance) return 'companion maintenance is on';
        return null;
    }

    summary_moved(name) { this.summaries_moved.add(name); }
    output_moved(name) { this.outputs_moved.add(name); }

    /** Run a tick as soon as the event loop is free. */
    kick() {
        setImmediate(() => this.tick());
    }

    child_started(name, pid, start_time) {
        this.children.push({ name, pid, start_time });
        children_file.write_children(this.config.children_file, this.children);
    }

    child_ended(pid) {
        this.children = this.children.filter((child) => child.pid !== pid);
        children_file.write_children(this.config.children_file, this.children);
    }

    /** Ends a record in Redis, now or as soon as Redis is back. */
    async end_record(record, descriptor) {
        try {
            await this.store.end_instance(record, descriptor.history_keep);
        } catch (err) {
            this.ended_backlog.push({ record, keep: descriptor.history_keep });
        }
    }

    /** Fires companion.error through the application, retried while it is not answering. */
    queue_event(event) {
        this.events.push(event);
        if (this.events.length > MAX_QUEUED_EVENTS) {
            const dropped = this.events.shift();
            this.log('[WARNING] an error report was dropped, the application is not answering: ' + dropped.message);
        }
    }

    async drain_events() {
        while (this.events.length > 0) {
            if (!(await this.app.event(this.events[0]))) return;
            this.events.shift();
        }
    }

    async start() {
        // A verb arriving between the bind and the first tick would meet a daemon that has
        // not read its descriptors yet and answer that a declared companion does not exist.
        // Verbs wait for that first read.
        let loaded;
        this.loaded = new Promise((resolve) => { loaded = resolve; });

        // The socket first: it is what proves no other daemon is alive, and only then are the
        // recorded process groups a dead daemon's orphans rather than a live one's children.
        await this.server.listen();

        for (const line of children_file.sweep(this.config.children_file)) {
            this.log('[WARNING] orphan swept: ' + line);
        }

        this.redis.on('connect', () => this.on_redis_connect());
        this.redis.on('disconnect', () => this.log('[WARNING] Redis connection lost'));
        this.redis.on('failure', (err) => this.log('[ERROR] Redis: ' + err.message));
        this.redis.start();

        if (this.realtime_redis !== null) {
            this.realtime_redis.on('failure', (err) => this.log('[ERROR] Redis (realtime): ' + err.message));
            this.realtime_redis.start();
        }

        this.log('[OK] started, pid ' + process.pid + ', host ' + this.config.host);

        await this.tick();
        loaded();
        this.timer = setInterval(() => this.tick(), TICK_MS);
    }

    async on_redis_connect() {
        if (!this.redis_ready) {
            try {
                const cleared = await this.store.clear_host((name) => {
                    const companion = this.companions.get(name);
                    return companion ? companion.descriptor.history_keep : 1;
                });
                if (cleared > 0) this.log('cleared ' + cleared + ' record(s) of a previous daemon');
                this.redis_ready = true;
            } catch (err) {
                this.log('[WARNING] could not clear old records: ' + err.message);
            }
        }

        this.log('[OK] Redis connected');
        this.kick();
    }

    async tick() {
        if (this.ticking) {
            this.tick_again = true;
            return;
        }

        this.ticking = true;
        try {
            do {
                this.tick_again = false;
                await this.tick_once();
            } while (this.tick_again);
        } catch (err) {
            this.log('[ERROR] tick: ' + err.stack);
        } finally {
            this.ticking = false;
        }
    }

    async tick_once() {
        this.tick_count++;

        await this.read_flags();
        await this.check_build();
        await this.check_epoch();

        for (const companion of this.companions.values()) {
            companion.step();
        }

        await this.flush_redis();
        await this.drain_events();
        this.resolve_settled();

        if (this.tick_count % SCAN_EVERY_TICKS === 0 && this.is_development()) {
            this.scan_sources();
        }

        if (this.tick_count % LOG_SWEEP_EVERY_TICKS === 0) {
            for (const companion of this.companions.values()) {
                if (companion.output !== null) {
                    companion.output.sweep().catch((err) => this.log('[WARNING] log sweep: ' + err.message));
                }
            }
        }
    }

    /** The flag files, the only place desired state and the overlay live. */
    async read_flags() {
        let entries = [];
        try {
            entries = await fs.promises.readdir(this.config.companion_state_dir);
        } catch (err) {
            // No directory: nobody has started anything.
        }

        const running = new Set(
            entries.filter((entry) => entry.endsWith('.running')).map((entry) => entry.slice(0, -'.running'.length))
        );

        this.overlay_maintenance = entries.includes(path.basename(this.config.overlay_flag_file));
        this.framework_maintenance = await path_exists(this.config.maintenance_flag_file);

        for (const companion of this.companions.values()) {
            companion.set_desired(running.has(companion.name));
        }
    }

    async check_build() {
        const key = await fs.promises.readFile(this.config.build_key_file, 'utf8').catch(() => null);
        const marker = await read_build_marker(this.config.manifest_marker_file);

        if (this.is_development() && marker !== this.build_marker_active) {
            this.build_marker_active = marker;
            for (const companion of this.companions.values()) {
                companion.on_build_marker(marker);
            }
        }

        this.build_present = key !== null;
        this.mode = config_module.current_mode();

        if (key === null || key === this.build_key || marker) return;

        const build_moved = this.build_key !== null;
        this.build_key = key;
        await this.reload_descriptors(build_moved);
    }

    /**
     * Reads every descriptor. A build that finished and no longer names a companion undeclares
     * it, but a missing or unreadable file otherwise changes nothing: rsx:clean removes this
     * directory, and that must never read as "every companion was deleted". Only a live
     * build key says the directory is whole, and the last good copy stays in memory until then.
     */
    async reload_descriptors(build_moved = false) {
        let names;
        try {
            names = await fs.promises.readdir(this.config.descriptors_root);
        } catch (err) {
            names = [];
        }

        const seen = new Set();

        for (const name of names) {
            const file = path.join(this.config.descriptors_root, name, 'descriptor.json');
            let descriptor;

            try {
                descriptor = JSON.parse(await fs.promises.readFile(file, 'utf8'));
            } catch (err) {
                this.log('[WARNING] unreadable descriptor kept at its last good copy: ' + file);
                seen.add(name);
                continue;
            }

            if (descriptor.schema !== 1 || descriptor.name !== name) {
                this.log('[WARNING] descriptor of an unknown shape ignored: ' + file);
                seen.add(name);
                continue;
            }

            seen.add(name);

            const companion = this.companions.get(name);
            if (companion === undefined) {
                this.companions.set(name, new Companion(name, descriptor, this));
                this.summary_moved(name);
            } else {
                companion.apply_descriptor(descriptor, build_moved);
                this.summary_moved(name);
            }
        }

        for (const [name, companion] of this.companions) {
            if (!seen.has(name)) companion.undeclare();
        }
    }

    /** Redis was emptied or evicted: everything this daemon knows goes back. */
    async check_epoch() {
        if (!this.redis.connected || !this.redis_ready) return;

        try {
            const first = this.store.epoch === null;
            if (await this.store.check_epoch()) {
                if (!first) this.log('Redis epoch moved: republishing');
                for (const companion of this.companions.values()) {
                    await companion.flush_record(true);
                    this.summary_moved(companion.name);
                }
                this.last_daemon_summary = 0;
            }
        } catch (err) {
            this.log('[WARNING] epoch check: ' + err.message);
        }
    }

    async flush_redis() {
        if (!this.redis.connected || !this.redis_ready) return;

        try {
            for (const entry of this.ended_backlog.splice(0)) {
                await this.store.end_instance(entry.record, entry.keep);
            }

            for (const companion of this.companions.values()) {
                await companion.flush_record(false);
            }

            await this.flush_summaries();
        } catch (err) {
            this.log('[WARNING] Redis write: ' + err.message);
        }
    }

    async flush_summaries() {
        const now = Date.now();
        const entries = {};

        for (const name of this.summaries_moved) {
            const companion = this.companions.get(name);
            if (companion !== undefined) entries[name] = companion.summary();
        }

        if (now - this.last_daemon_summary >= DAEMON_SUMMARY_MS) {
            entries[DAEMON_SUMMARY_FIELD] = this.daemon_summary();
            this.last_daemon_summary = now;
        }

        const moved = Array.from(this.summaries_moved);
        const output = Array.from(this.outputs_moved);
        this.summaries_moved.clear();
        this.outputs_moved.clear();

        if (Object.keys(entries).length > 0) {
            await this.store.write_summaries(entries);
        }

        await this.store.publish_events(
            moved.map((name) => ({ kind: 'changed', name })).concat(output.map((name) => ({ kind: 'output', name })))
        );
    }

    daemon_summary() {
        return {
            pid: process.pid,
            host: this.config.host,
            started_at: this.started_at,
            heartbeat: Date.now(),
            mode: this.mode,
            maintenance: this.maintenance_reason(),
        };
    }

    /** A development tree: its own resource/ raises no manifest signal. */
    async scan_sources() {
        for (const companion of this.companions.values()) {
            if (companion.child === null) continue;

            const directory = path.join(this.config.project_root, companion.descriptor.directory);

            try {
                companion.on_source_scan(await fingerprint.source_hash(directory));
            } catch (err) {
                // A file vanished mid-walk: a save in progress. The next scan sees the result.
            }
        }
    }

    resolve_settled() {
        if (this.settle_waiters.length === 0) return;

        for (const companion of this.companions.values()) {
            if (!companion.is_settled()) return;
        }

        for (const resolve of this.settle_waiters.splice(0)) {
            resolve();
        }
    }

    /** Resolves when no stop or start is in flight. */
    async settled() {
        await this.tick();
        await new Promise((resolve) => {
            this.settle_waiters.push(resolve);
            this.kick();
        });
    }

    require_companion(name) {
        const companion = this.companions.get(name);
        if (companion === undefined) throw new Error('No companion is declared with the name ' + name);
        return companion;
    }

    /**
     * Control socket verbs, after hello. Stated in WIRE_CONTRACT:
     *   status  {name?}                                 the daemon and each companion
     *   reload  {wait?}                                 flags changed: read them now
     *   control {name, action, wait?}                   pause, resume, stop, start, restart
     */
    async handle_verb(frame) {
        await this.loaded;

        switch (frame.verb) {
            case 'status': {
                const companions = frame.name === undefined
                    ? Array.from(this.companions.values())
                    : [this.require_companion(frame.name)];

                return { daemon: this.daemon_summary(), companions: companions.map((c) => c.summary()) };
            }

            case 'reload':
                await this.check_build();
                await this.tick();
                if (frame.wait) await this.settled();
                return {};

            case 'control': {
                const targets = frame.name === undefined
                    ? Array.from(this.companions.values())
                    : [this.require_companion(frame.name)];

                for (const companion of targets) {
                    companion.control(frame.action);
                }

                await this.tick();
                if (frame.wait) await this.settled();
                return {};
            }

            default:
                throw new Error('Unknown verb: ' + frame.verb);
        }
    }

    /** Every child, in parallel, each on its own grace, then out. */
    async shutdown(signal) {
        if (this.shutting_down) return;
        this.shutting_down = true;
        this.log('stopping on ' + signal);

        clearInterval(this.timer);

        const exits = [];
        for (const companion of this.companions.values()) {
            if (companion.child !== null) {
                exits.push(new Promise((resolve) => {
                    companion.child.on('exit', () => setImmediate(resolve));
                }));
                companion.begin_stop('shutdown');
            }
        }

        await Promise.all(exits);

        // Their end-of-instance writes are queued behind the exits.
        for (const companion of this.companions.values()) {
            if (companion.output !== null) await companion.output.close();
        }

        if (this.redis.connected) {
            await this.flush_redis();
            for (const name of this.companions.keys()) {
                await this.store.delete_summary(name).catch(() => {});
            }
        }

        this.server.close();
        this.redis.stop();
        if (this.realtime_redis !== null) this.realtime_redis.stop();
        this.log('[OK] stopped');
        process.exit(0);
    }
}

function path_exists(file) {
    return fs.promises.access(file).then(() => true, () => false);
}

/**
 * A manifest build in progress. The marker holds the builder's pid and its kernel start time
 * (`<pid> <start time>`), because a builder killed outright never removes it and a pause must
 * still end: once the builder is gone its pid may name an unrelated process, which the start
 * time tells apart.
 */
async function read_build_marker(file) {
    const text = await fs.promises.readFile(file, 'utf8').catch(() => null);
    if (text === null) return false;

    const [pid, start_time] = text.trim().split(' ');
    if (!/^\d+$/.test(pid || '') || !/^\d+$/.test(start_time || '')) return false;

    const stat = children_file.proc_stat(pid);

    return stat !== null && stat.state !== 'Z' && stat.state !== 'X' && stat.start_time === start_time;
}

function parse_options(argv) {
    const options = { project_root: null, build_root: null, host: null };

    for (const arg of argv) {
        const match = /^--(project-root|build-root|host)=(.+)$/.exec(arg);
        if (match === null) throw new Error('Unknown argument: ' + arg);
        options[match[1].replace('-', '_')] = match[2];
    }

    return options;
}

async function main() {
    const verb = process.argv[2];

    if (verb !== 'run') {
        process.stderr.write('Usage: companiond.js run [--project-root=<dir>] [--build-root=<dir>] [--host=<name>]\n');
        process.exit(2);
    }

    const config = config_module.load(parse_options(process.argv.slice(3)));

    if (config.app_key === '') {
        // A fresh install has no key yet. Nothing can be signed, so nothing can start: wait
        // for a supervisor restart rather than walking the FATAL path.
        process.stdout.write('[companiond] [WARNING] APP_KEY is not set; idle until it is, then restart this program\n');
        setInterval(() => {}, TICK_MS * 3600);
        return;
    }

    const daemon = new Daemon(config);

    // A daemon crash is a mass stop of every companion: a stray bug is logged, never fatal.
    process.on('uncaughtException', (err) => daemon.log('[ERROR] uncaught: ' + err.stack));
    process.on('unhandledRejection', (err) => daemon.log('[ERROR] unhandled: ' + (err && err.stack)));

    process.on('SIGTERM', () => daemon.shutdown('SIGTERM'));
    process.on('SIGINT', () => daemon.shutdown('SIGINT'));
    process.on('SIGHUP', () => {});

    await daemon.start();
}

if (require.main === module) {
    main().catch((err) => {
        process.stderr.write('[companiond] [ERROR] ' + err.message + '\n');
        process.exit(1);
    });
}

module.exports = { Daemon, parse_options, read_build_marker };
