/**
 * One declared companion: the decision whether it should run, and its current process.
 *
 * step() is the whole decision and is idempotent: every event (a tick, an exit, a grant, a
 * control verb) just calls it. A companion runs when all of these hold, checked in this order:
 * desired, no maintenance, not held by an operator, a build, Redis, the election, then the
 * application's own readiness. Those are gates on STARTING. A running child is stopped only for
 * a desire, a maintenance window or a lost election, never because the application went away:
 * it rides an outage out on the SDK's retries.
 *
 * WAITING NEVER COUNTS. restart_count moves only when a process is actually spawned as a
 * restart, so a companion waiting on an install for a month shows zero.
 */

const child_process = require('child_process');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const { StringDecoder } = require('string_decoder');

const backoff = require('./backoff.js');
const children_file = require('./children_file.js');
const fingerprint = require('./fingerprint.js');
const lease_module = require('./lease.js');
const { Output_Log } = require('./output_log.js');
const wire = require('./wire.js');

/** How often a waiting companion asks the application again. A poll interval, not a bound. */
const READY_RECHECK_MS = 15000;

/** A child that has lived this long is shown as running, whether or not it uses the SDK. */
const STARTING_DISPLAY_MS = 1000;

/** The most often a record is rewritten with nothing to say: the 30 s continuity rewrite. */
const RECORD_REWRITE_MS = 30000;

/** A longer line is split. A memory bound on one line, not a limit on output. */
const MAX_LINE_BYTES = 64 * 1024;

/** A report larger than this is dropped and logged. A memory bound on the parser. */
const MAX_REPORT_BYTES = 256 * 1024;

/** What is held for the live stream while Redis is behind. It is lossy by design. */
const MAX_MIRROR_BACKLOG = 2000;

/** Splits a byte stream into lines, delivering each. A partial line waits for its end. */
function line_splitter(max_bytes, deliver) {
    const decoder = new StringDecoder('utf8');
    let pending = '';

    return {
        write(chunk) {
            pending += decoder.write(chunk);

            let newline;
            while ((newline = pending.indexOf('\n')) !== -1) {
                deliver(pending.slice(0, newline).replace(/\r$/, ''));
                pending = pending.slice(newline + 1);
            }

            while (pending.length > max_bytes) {
                deliver(pending.slice(0, max_bytes));
                pending = pending.slice(max_bytes);
            }
        },
        end() {
            pending += decoder.end();
            if (pending !== '') deliver(pending);
            pending = '';
        },
    };
}

class Companion {
    constructor(name, descriptor, daemon) {
        this.name = name;
        this.descriptor = descriptor;
        this.daemon = daemon;
        this.config = daemon.config;

        this.desired = false;
        this.held = false;              // an operator stopped it; desired is untouched
        this.finished = false;          // its restart policy said to leave it stopped
        this.undeclared = false;

        this.phase = 'stopped';         // stopped waiting backoff starting running paused stopping ended
        this.waiting_reason = null;
        this.next_start_at = 0;
        this.next_ready_at = 0;
        this.ready_in_flight = false;
        this.starting = false;

        this.child = null;
        this.record = null;
        this.stop_kind = null;
        this.kill_timer = null;
        this.display_timer = null;
        this.restart_pending = false;
        this.restart_due = false;
        this.paused_by_build = false;
        this.sdk_attached = false;
        this.last_record_write = 0;
        this.record_dirty = false;

        this.restart_count = 0;
        // crash_streak counts failures only, and is what health calls crash-looping;
        // backoff_streak spaces every restart, clean exits included, so neither can spin.
        this.crash_streak = 0;
        this.backoff_streak = 0;
        this.last_exit = null;
        this.scan_candidate = null;

        this.lease = null;
        this.output = null;
        this.mirror_backlog = [];
        this.mirroring = false;
    }

    log(message) {
        this.daemon.log(this.name + ': ' + message);
    }

    /** The output pipeline. Created once, on first use, and lives as long as the daemon does. */
    output_log() {
        if (this.output === null) {
            this.output = new Output_Log({
                directory: this.daemon.logs_dir(this.name),
                name: this.name,
                logging: this.descriptor.logging,
                owner: this.config.identity,
                mirror: (batch) => this._mirror(batch),
                report: (message) => this.daemon.log(message),
            });
        }
        return this.output;
    }

    _mirror(batch) {
        this.mirror_backlog.push(...batch);
        if (this.mirror_backlog.length > MAX_MIRROR_BACKLOG) {
            this.mirror_backlog.splice(0, this.mirror_backlog.length - MAX_MIRROR_BACKLOG);
        }
        this.daemon.output_moved(this.name);

        if (!this.mirroring) {
            this.mirroring = true;
            this._drain_mirror();
        }
    }

    async _drain_mirror() {
        while (this.mirror_backlog.length > 0 && this.daemon.redis.connected) {
            const batch = this.mirror_backlog.splice(0, this.mirror_backlog.length);
            try {
                await this.daemon.store.append_output(this.name, batch, this.descriptor.output_stream_max_lines);
            } catch (err) {
                break;
            }
        }
        this.mirroring = false;
    }

    is_wanted() {
        return this.desired
            && !this.held
            && !this.finished
            && !this.undeclared
            && !this.daemon.shutting_down
            && this.daemon.maintenance_reason() === null;
    }

    /** Settled = nothing in flight that a waiting command should wait for. */
    is_settled() {
        if (this.starting || this.restart_pending || this.phase === 'stopping') return false;
        return this.child === null || this.is_wanted();
    }

    set_desired(desired) {
        if (desired === this.desired) return;
        this.desired = desired;
        this.held = false;
        this.finished = false;
        this.next_start_at = 0;
        this.crash_streak = 0;
        this.backoff_streak = 0;
        this.daemon.summary_moved(this.name);
    }

    wait(reason) {
        if (this.phase !== 'waiting' || this.waiting_reason !== reason) {
            this.phase = 'waiting';
            this.waiting_reason = reason;
            this.daemon.summary_moved(this.name);
        }
    }

    _set_phase(phase) {
        if (this.phase === phase) return;
        this.phase = phase;
        if (phase !== 'waiting') this.waiting_reason = null;
        this.daemon.summary_moved(this.name);
    }

    /** The whole decision. */
    step() {
        if (this.child !== null) {
            this._step_running();
            return;
        }

        if (this.starting) return;

        if (!this.is_wanted()) {
            this._release_lease();
            this.restart_pending = false;
            if (this.phase !== 'ended') this._set_phase('stopped');
            return;
        }

        if (this.phase === 'backoff' && Date.now() < this.next_start_at) return;

        if (!this.daemon.build_present) {
            this.wait('no build is present');
            return;
        }

        if (!this.daemon.redis_ready) {
            this.wait('Redis is not reachable');
            return;
        }

        if (this.descriptor.single_instance && !this._step_lease()) return;

        if (this.ready_in_flight || Date.now() < this.next_ready_at) return;

        this._ask_ready();
    }

    _step_running() {
        if (this.phase === 'stopping') return;

        if (!this.is_wanted()) {
            this.begin_stop('stop');
        } else if (this.descriptor.single_instance
            && (this.lease === null || this.lease.state !== lease_module.STATE_HELD)) {
            this.log('[WARNING] lost the election: stopping, because another host may now hold it');
            this.begin_stop('lease');
        }
    }

    /** Answers true when this daemon holds the election. */
    _step_lease() {
        if (this.lease === null) {
            this.lease = new lease_module.Instance_Lease({
                lock_name: lease_module.lock_name(this.config.token, this.name),
                lockd: this.config.lockd,
                hmac_key: this.config.app_key,
                on_change: () => this.daemon.kick(),
            });
        }

        switch (this.lease.state) {
            case lease_module.STATE_HELD:
                return true;

            case lease_module.STATE_PARKED:
                this.wait('another host holds the instance');
                return false;

            case lease_module.STATE_UNREACHABLE:
                this.wait('rsx-lockd is unreachable: ' + this.lease.reason);
                if (Date.now() >= this.next_ready_at) {
                    this.next_ready_at = Date.now() + READY_RECHECK_MS;
                    this.lease.acquire();
                }
                return false;

            default:
                this.lease.acquire();
                if (this.phase !== 'waiting') this.wait('connecting to rsx-lockd');
                return false;
        }
    }

    _release_lease() {
        if (this.lease !== null) {
            this.lease.release();
            this.lease = null;
        }
    }

    async _ask_ready() {
        this.ready_in_flight = true;
        if (this.phase !== 'waiting') this.wait('asking the application');

        const answer = await this.daemon.app.ready(this.name);

        this.ready_in_flight = false;

        if (!this.is_wanted() || this.child !== null) {
            this.daemon.kick();
            return;
        }

        if (!answer.ready) {
            this.next_ready_at = Date.now() + READY_RECHECK_MS;
            this.wait(answer.scope === 'install' ? 'install not ready: ' + answer.reason : answer.reason);
            return;
        }

        this.next_ready_at = 0;
        await this._start_instance();
    }

    async _start_instance() {
        this.starting = true;

        try {
            const descriptor = this.descriptor;
            const directory = path.join(this.config.project_root, descriptor.directory);
            const development = this.daemon.is_development();

            const source_hash = development
                ? await fingerprint.source_hash(directory).catch(() => descriptor.source_hash)
                : descriptor.source_hash;

            const instance_id = 'i-' + crypto.randomBytes(12).toString('hex');
            const started_at = String(Date.now());

            this.record = {
                instance_id,
                name: this.name,
                host: this.daemon.host(),
                pid: '',
                started_at,
                status: 'starting',
                contract_key: descriptor.endpoints_hash,
                source_hash,
                restart_count: this.restart_count + (this.restart_due ? 1 : 0),
                last_heartbeat: '',
                status_line: '',
                state: '',
                progress_current: '',
                progress_total: '',
                last_error: '',
            };

            // Before the spawn: the child's first call must find it.
            await this.daemon.store.write_instance(this.record);

            if (!this.is_wanted()) {
                await this.daemon.end_record(this._finish_record('exited', 'never_started', null, null), descriptor);
                return;
            }

            this.restart_count = this.record.restart_count;
            this.restart_due = false;
            this.restart_pending = false;
            this._spawn(descriptor, directory);
        } catch (err) {
            this.record = null;
            this.next_ready_at = Date.now() + READY_RECHECK_MS;
            this.wait('could not start: ' + err.message);
        } finally {
            this.starting = false;
            this.daemon.kick();
        }
    }

    _child_environment(descriptor) {
        const record = this.record;

        return Object.assign({}, this.daemon.child_base_env(), descriptor.environment, {
            RSX_COMPANION_NAME: this.name,
            RSX_COMPANION_INSTANCE: record.instance_id,
            RSX_COMPANION_STARTED_AT: record.started_at,
            RSX_COMPANION_SECRET: wire.instance_secret(this.config.app_key, this.name, record.instance_id, record.started_at),
            RSX_COMPANION_BASE_URL: this.config.base_url,
            RSX_COMPANION_CONTRACT_KEY: descriptor.endpoints_hash,
            RSX_COMPANION_CLIENT: path.join(this.config.descriptors_root, this.name, 'client.js'),
        });
    }

    _spawn(descriptor, directory) {
        const command = descriptor.command[0] === 'node' ? process.execPath : descriptor.command[0];
        const identity = this.config.identity;

        const options = {
            cwd: path.join(directory, descriptor.working_directory),
            env: this._child_environment(descriptor),
            // Its own session, so its process group is its own and supervisor's signals, which
            // go to the daemon's group, leave the stop to the daemon.
            detached: true,
            // fd 3 control, fd 4 reports. Node marks every other descriptor close-on-exec, so
            // neither a lock connection nor another child's pipe can leak in.
            stdio: ['ignore', 'pipe', 'pipe', 'pipe', 'pipe'],
        };

        if (identity !== null) {
            options.uid = identity.uid;
            options.gid = identity.gid;
        }

        const child = child_process.spawn(command, descriptor.command.slice(1), options);

        // Fired, in place of 'exit', when the executable could not be run at all.
        child.on('error', (err) => {
            if (child.pid === undefined) this._on_spawn_failure(err);
        });

        if (child.pid === undefined) {
            this.child = child;
            return;
        }

        this.child = child;
        this.spawned_at = Date.now();
        this.sdk_attached = false;
        this.stop_kind = null;
        this.record.pid = String(child.pid);
        this.record_dirty = true;
        this._set_phase('starting');

        const stat = children_file.proc_stat(child.pid);
        this.daemon.child_started(this.name, child.pid, stat === null ? '' : stat.start_time);

        const output = this.output_log();
        const instance_id = this.record.instance_id;

        output.push('daemon', instance_id, 'instance ' + instance_id + ' started, pid ' + child.pid);

        const out = line_splitter(MAX_LINE_BYTES, (line) => output.push('stdout', instance_id, line));
        const err = line_splitter(MAX_LINE_BYTES, (line) => output.push('stderr', instance_id, line));
        child.stdout.on('data', (chunk) => out.write(chunk));
        child.stderr.on('data', (chunk) => err.write(chunk));
        child.stdout.on('end', () => out.end());
        child.stderr.on('end', () => err.end());

        for (const pipe of [child.stdin, child.stdout, child.stderr, child.stdio[3], child.stdio[4]]) {
            if (pipe) pipe.on('error', () => {});
        }

        const reports = line_splitter(MAX_REPORT_BYTES, (line) => this._on_report(line));
        child.stdio[4].on('data', (chunk) => reports.write(chunk));

        child.on('exit', (code, signal) => {
            this._on_exit(code, signal).catch((failure) => this.daemon.log('[ERROR] ' + failure.stack));
        });

        this.display_timer = setTimeout(() => {
            if (this.phase === 'starting') this._mark_running();
        }, STARTING_DISPLAY_MS);
    }

    _on_spawn_failure(err) {
        this.child = null;
        this.log('[ERROR] could not run ' + this.descriptor.command[0] + ': ' + err.message);
        this.output_log().push('daemon', this.record.instance_id, '[ERROR] could not run: ' + err.message);
        this._after_exit(null, null, err.message, 0).catch((failure) => this.daemon.log('[ERROR] ' + failure.stack));
    }

    _mark_running() {
        this._set_record('status', 'running');
        this._set_phase('running');
    }

    _set_record(field, value) {
        if (this.record !== null && this.record[field] !== value) {
            this.record[field] = value;
            this.record_dirty = true;
            this.daemon.summary_moved(this.name);
        }
    }

    _on_report(line) {
        let report;
        try {
            report = JSON.parse(line);
        } catch (err) {
            this.log('[WARNING] an unreadable report was dropped');
            return;
        }

        if (this.record === null) return;

        switch (report.type) {
            case 'ready':
                this.sdk_attached = true;
                if (this.phase === 'starting') this._mark_running();
                break;

            case 'status':
                this._set_record('status_line', String(report.text));
                break;

            case 'state':
                this._set_record('state', JSON.stringify(report.value));
                break;

            case 'progress':
                this._set_record('progress_current', String(report.current));
                this._set_record('progress_total', report.total === undefined || report.total === null ? '' : String(report.total));
                break;

            case 'heartbeat':
                // Not "dirty": a heartbeat alone reaches Redis with the next ~30 s rewrite.
                this.record.last_heartbeat = String(Date.now());
                break;

            case 'error':
                this._on_reported_error(report);
                break;

            default:
                this.log('[WARNING] an unknown report type was dropped: ' + report.type);
        }
    }

    _on_reported_error(report) {
        this._set_record('last_error', String(report.code) + ': ' + String(report.message));
        this.daemon.queue_event({
            name: this.name,
            instance_id: this.record.instance_id,
            code: String(report.code),
            message: String(report.message),
            severity: report.severity === undefined ? 'error' : String(report.severity),
            context: report.context === undefined ? {} : report.context,
        });
    }

    /** One control message on fd 3. A child that never reads it never blocks us: a write queues. */
    _control(message) {
        const pipe = this.child.stdio[3];
        if (pipe && !pipe.destroyed) pipe.write(JSON.stringify(message) + '\n');
    }

    /** The stop sequence: control message, SIGTERM to the group, grace, SIGKILL to the group. */
    begin_stop(kind) {
        if (this.child === null || this.phase === 'stopping') return;

        this.stop_kind = kind;
        this._set_record('status', 'stopping');
        this._set_phase('stopping');

        this._control({ type: 'stop' });
        this._signal_group('SIGTERM');

        // The companion's declared grace, a sanctioned bound: SIGKILL is the second half of a
        // stop the operator asked for, and idempotent calls are what make it safe. Its value is
        // the application's stated answer to the 599/601 question, capped at build by
        // MAX_SHUTDOWN_GRACE_SECONDS.
        this.kill_timer = setTimeout(() => {
            this.killed_by_us = true;
            this.log('[WARNING] still running after ' + this.descriptor.shutdown_grace_seconds + ' s: SIGKILL');
            this._signal_group('SIGKILL');
        }, this.descriptor.shutdown_grace_seconds * 1000);
    }

    _signal_group(signal) {
        try {
            process.kill(-this.child.pid, signal);
        } catch (err) {
            if (err.code !== 'ESRCH') this.log('[WARNING] could not signal the group: ' + err.message);
        }
    }

    async _on_exit(code, signal) {
        const run_ms = Date.now() - this.spawned_at;

        clearTimeout(this.kill_timer);
        clearTimeout(this.display_timer);

        // Whatever the child left in its group goes with it, on every exit, so a crash cannot
        // leak a grandchild into the next run.
        children_file.kill_group(this.child.pid);
        this.daemon.child_ended(this.child.pid);

        this.child = null;

        await this._after_exit(code, signal, null, run_ms);
    }

    _finish_record(status, how_ended, code, signal) {
        const record = this.record;
        record.status = status;
        record.how_ended = how_ended;
        record.exit_code = code === null ? '' : String(code);
        record.exit_signal = signal === null ? '' : String(signal);
        record.ended_at = String(Date.now());
        this.record = null;
        return record;
    }

    async _after_exit(code, signal, failure, run_ms) {
        const descriptor = this.descriptor;
        const stop_kind = this.stop_kind;
        const killed_by_us = this.killed_by_us === true;
        const clean = code === 0;
        const contract = code === wire.EXIT_CONTRACT_MISMATCH && !stop_kind;

        let status;
        let how_ended;

        if (stop_kind !== null) {
            status = killed_by_us ? 'killed' : 'exited';
            how_ended = killed_by_us ? 'killed' : 'stopped';
        } else if (contract) {
            status = 'exited';
            how_ended = 'contract_mismatch';
        } else if (clean) {
            status = 'exited';
            how_ended = 'exited';
        } else {
            status = 'crashed';
            how_ended = 'crashed';
        }

        const instance_id = this.record.instance_id;
        const contract_key = this.record.contract_key;
        const summary = failure !== null ? failure : 'exit ' + (signal !== null ? signal : code);

        this.output_log().push('daemon', instance_id, 'instance ' + instance_id + ' ended: ' + how_ended + ' (' + summary + ')');

        const record = this._finish_record(status, how_ended, code, signal);
        this.last_exit = { instance_id, status, how_ended, code, signal, at: Date.now() };
        this.stop_kind = null;
        this.killed_by_us = false;
        this.paused_by_build = false;

        await this.daemon.end_record(record, descriptor);

        if (status === 'crashed') {
            this.daemon.queue_event({
                name: this.name,
                instance_id,
                code: 'crashed',
                message: this.name + ' crashed: ' + summary,
                severity: 'error',
                context: { exit_code: code, signal },
            });
        }

        // A stop this daemon asked for is not a failure: what happens next is step()'s.
        if (stop_kind !== null) {
            this.restart_due = this.restart_pending;
            this._set_phase('stopped');
            this.next_start_at = 0;
            this.daemon.kick();
            return;
        }

        if (contract) {
            await this.daemon.reload_descriptors();

            // The contract moved under it: not a failure, and it restarts on the new one. The
            // same key again means restarting will not help, so it falls through to backoff.
            if (this.descriptor.endpoints_hash !== contract_key) {
                this.restart_due = true;
                this._set_phase('stopped');
                this.daemon.kick();
                return;
            }
        }

        const policy = descriptor.restart_policy;
        const failed = !clean;

        if (policy === 'never' || (policy === 'on_failure' && !failed)) {
            this.finished = true;
            this._set_phase('ended');
            return;
        }

        // A clean exit under "always" is delayed like a crash, so a companion that exits at
        // once cannot spin, but it is not a crash: only a failure adds to crash_streak.
        this.backoff_streak = backoff.next_streak(this.backoff_streak, run_ms);
        this.crash_streak = failed ? backoff.next_streak(this.crash_streak, run_ms) : 0;
        const delay = backoff.restart_delay_ms(
            this.backoff_streak,
            descriptor.restart_backoff_initial_seconds,
            descriptor.restart_backoff_ceiling_seconds
        );

        this.restart_due = true;
        this.next_start_at = Date.now() + delay;
        this.next_ready_at = 0;
        this._set_phase('backoff');
        this.log('exited (' + summary + '); restarting in ' + (delay / 1000) + ' s');

        setTimeout(() => this.daemon.kick(), delay);
    }

    /** An operator's verb. `action`: pause, resume, stop, start, restart. */
    control(action) {
        switch (action) {
            case 'pause':
            case 'resume':
                if (this.child === null || this.phase === 'stopping') {
                    throw new Error(this.name + ' is not running');
                }
                if (!this.sdk_attached) {
                    throw new Error(this.name + ' does not use the SDK: pause and resume cannot reach it');
                }
                this._control({ type: action });
                this._set_record('status', action === 'pause' ? 'paused' : 'running');
                this._set_phase(action === 'pause' ? 'paused' : 'running');
                break;

            case 'stop':
                this.held = true;
                this.finished = false;
                break;

            case 'start':
                this.held = false;
                this.finished = false;
                this.next_start_at = 0;
                this.next_ready_at = 0;
                break;

            case 'restart':
                this.request_restart('restart');
                break;

            default:
                throw new Error('Unknown action: ' + action);
        }
    }

    /** Stop and start again at once, with no backoff and no change to desired state. */
    request_restart(kind) {
        this.held = false;
        this.finished = false;
        this.next_start_at = 0;
        this.next_ready_at = 0;

        if (this.child !== null && this.phase !== 'stopping') {
            this.restart_pending = true;
            this.begin_stop(kind);
        } else if (this.child === null && this.is_wanted()) {
            this.restart_due = true;
        }
    }

    /** A finished build delivered this descriptor. */
    apply_descriptor(next, build_moved) {
        this.descriptor = next;
        this.undeclared = false;

        if (this.child === null || this.record === null) return;

        const contract_moved = next.endpoints_hash !== this.record.contract_key;

        if (!this.daemon.is_development()) {
            // Production ignores the policies: code arrives by deploy, and a changed descriptor
            // is how the daemon learns of it.
            if (contract_moved || next.source_hash !== this.record.source_hash) {
                this.request_restart('code');
            }
            return;
        }

        if (next.on_code_change === 'restart') {
            if (build_moved) this.request_restart('code');
        } else if (next.on_code_change === 'restart_if_affected' && contract_moved) {
            this.request_restart('code');
        }
    }

    /**
     * Development: the directory hashed to `hash`. The same new value on two scans is required,
     * so a multi-file save restarts it once, on the finished tree.
     */
    on_source_scan(hash) {
        const policy = this.descriptor.on_code_change;
        if (policy !== 'restart_if_affected' && policy !== 'restart') return;
        if (this.record === null || this.phase === 'stopping') return;

        if (hash === this.record.source_hash) {
            this.scan_candidate = null;
        } else if (hash === this.scan_candidate) {
            this.scan_candidate = null;
            this.log('source changed: restarting');
            this.request_restart('code');
        } else {
            this.scan_candidate = hash;
        }
    }

    /** Development: the manifest build marker appeared or went away. Best effort by design. */
    on_build_marker(active) {
        if (this.descriptor.on_code_change !== 'pause' || !this.sdk_attached || this.child === null) return;

        if (active && this.phase === 'running') {
            this.paused_by_build = true;
            this.control('pause');
        } else if (!active && this.paused_by_build) {
            this.paused_by_build = false;
            this.control('resume');
        }
    }

    undeclare() {
        this.undeclared = true;
        this.daemon.summary_moved(this.name);
    }

    /** Write the record when it has news, or when the continuity rewrite is due. */
    async flush_record(force) {
        if (this.record === null || this.starting) return;

        const now = Date.now();
        if (!force && !this.record_dirty && now - this.last_record_write < RECORD_REWRITE_MS) return;

        this.record_dirty = false;
        this.last_record_write = now;
        await this.daemon.store.write_instance(this.record);
    }

    summary() {
        const record = this.record;
        const descriptor = this.descriptor;

        return {
            name: this.name,
            description: descriptor.description,
            host: this.daemon.host(),
            desired: this.desired ? 'running' : 'stopped',
            held: this.held,
            status: this.phase === 'backoff' ? (this.last_exit && this.last_exit.status === 'exited' ? 'exited' : 'crashed') : (this.phase === 'ended' && this.last_exit ? this.last_exit.status : this.phase),
            waiting_reason: this.waiting_reason,
            single_instance: descriptor.single_instance,
            instance_id: record ? record.instance_id : null,
            pid: this.child && this.child.pid ? this.child.pid : null,
            started_at: record ? record.started_at : null,
            contract_key: descriptor.endpoints_hash,
            restart_count: this.restart_count,
            crash_streak: this.crash_streak,
            next_restart_at: this.phase === 'backoff' ? this.next_start_at : null,
            last_heartbeat: record ? record.last_heartbeat : null,
            status_line: record ? record.status_line : null,
            state: record ? record.state : null,
            progress_current: record ? record.progress_current : null,
            progress_total: record ? record.progress_total : null,
            last_error: record ? record.last_error : null,
            last_exit: this.last_exit,
        };
    }
}

module.exports = { Companion, line_splitter, READY_RECHECK_MS };
