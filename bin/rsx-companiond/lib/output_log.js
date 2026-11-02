/**
 * One companion's output: a bounded queue between its pipes and its log file.
 *
 * THE PIPES ARE ALWAYS READ. A child that fills a pipe blocks on write, so nothing here ever
 * pauses a read: lines go into the queue, and when the writer cannot keep up the queue drops
 * lines, counts them, and writes one marker once it has room. A slow disk costs log lines,
 * never a stalled companion.
 *
 * ROTATION is by size and daily, done by this writer because it holds the file: the descriptor
 * is closed, the generations shift, a new file opens. So a long-lived holder can never drift
 * off the file's name, which is what a rename by anybody else would do to it. Names are
 * Rsx_Logrotate's (name.log.N, name.log.N.gz) and Rsx_Logrotate never sees this directory: a
 * size-triggered generation is not a day, so a generation's AGE (its mtime) decides compression
 * and retention. days_retention 0 keeps everything forever.
 *
 * Compression runs on streams in the background, never inline, so a large generation holds up
 * neither the writer nor anything else on the event loop.
 */

const fs = require('fs');
const path = require('path');
const zlib = require('zlib');
const { pipeline } = require('stream/promises');

/** Lines held for a writer that is behind. A memory bound: the excess is dropped and counted. */
const MAX_QUEUE_LINES = 20000;
const MAX_QUEUE_BYTES = 32 * 1024 * 1024;

/** One write's worth of lines, so a long backlog still reaches the rotation check. */
const MAX_BATCH_LINES = 1000;

const DAY_MS = 24 * 60 * 60 * 1000;

function iso_now() {
    return new Date().toISOString();
}

function local_day(ms) {
    return new Date(ms).toDateString();
}

class Output_Log {
    /**
     * @param {object} options
     * @param {string} options.directory  The companion's log directory
     * @param {string} options.name       The companion's name
     * @param {{max_bytes: number, days_uncompressed: number, days_retention: number}} options.logging
     * @param {?{uid: number, gid: number}} options.owner  Chown created files to this identity
     * @param {function(Array)} options.mirror  Handed every written batch: the live stream
     * @param {function(string)} options.report Where the writer's own failures go
     */
    constructor(options) {
        this.directory = options.directory;
        this.name = options.name;
        this.logging = options.logging;
        this.owner = options.owner;
        this.mirror = options.mirror;
        this.report = options.report;

        this.file = path.join(this.directory, this.name + '.log');
        this.handle = null;
        this.size = 0;
        this.day = null;

        this.queue = [];
        this.queue_bytes = 0;
        this.dropped = 0;
        this.draining = null;
        this.compressing = false;
        this.fs_lock = Promise.resolve();
        this.last_failure_report = 0;
    }

    /** Queue one line. Never blocks and never throws. */
    push(stream, instance_id, line) {
        const bytes = Buffer.byteLength(line);

        if (this.queue.length >= MAX_QUEUE_LINES || this.queue_bytes + bytes > MAX_QUEUE_BYTES) {
            this.dropped++;
            return;
        }

        this.queue.push({ at: iso_now(), stream, instance_id, line });
        this.queue_bytes += bytes;

        if (this.draining === null) {
            this.draining = this._drain();
        }
    }

    /** Resolves when everything queued has been written. */
    async flush() {
        while (this.draining !== null) {
            await this.draining;
        }
    }

    async close() {
        await this.flush();
        if (this.handle) {
            const handle = this.handle;
            this.handle = null;
            await handle.close();
        }
    }

    async _drain() {
        while (this.queue.length > 0) {
            const batch = this.queue.splice(0, MAX_BATCH_LINES);

            for (const entry of batch) {
                this.queue_bytes -= Buffer.byteLength(entry.line);
            }

            if (this.dropped > 0) {
                batch.push({
                    at: iso_now(),
                    stream: 'daemon',
                    instance_id: batch[batch.length - 1].instance_id,
                    line: '[WARNING] ' + this.dropped + ' line(s) dropped: output outran the log writer',
                });
                this.dropped = 0;
            }

            try {
                await this._write(batch);
            } catch (err) {
                this._report_failure(err);
            }

            this.mirror(batch);
        }

        this.draining = null;
    }

    async _write(batch) {
        const text = batch
            .map((entry) => '[' + entry.at + '] [' + entry.stream + '] ' + entry.line + '\n')
            .join('');
        const bytes = Buffer.byteLength(text);

        await this._open();

        const now = Date.now();
        const over_size = this.logging.max_bytes > 0 && this.size > 0 && this.size + bytes > this.logging.max_bytes;
        const new_day = this.size > 0 && this.day !== local_day(now);

        if (over_size || new_day) {
            await this._rotate();
            await this._open();
        }

        await this.handle.write(text);
        this.size += bytes;
        this.day = local_day(now);
    }

    async _open() {
        if (this.handle !== null) return;

        await fs.promises.mkdir(this.directory, { recursive: true });
        this._chown(path.dirname(this.directory));
        this._chown(this.directory);

        this.handle = await fs.promises.open(this.file, 'a', 0o640);
        this._chown(this.file);

        const stat = await this.handle.stat();
        this.size = stat.size;
        this.day = local_day(stat.size > 0 ? stat.mtimeMs : Date.now());
    }

    _chown(target) {
        if (this.owner === null) return;
        try {
            fs.chownSync(target, this.owner.uid, this.owner.gid);
        } catch (err) {
            this._report_failure(err);
        }
    }

    _report_failure(err) {
        // Once a minute at most: a full disk would otherwise report every batch.
        const now = Date.now();
        if (now - this.last_failure_report < 60000) return;
        this.last_failure_report = now;
        this.report('[WARNING] ' + this.name + ' log: ' + err.message);
    }

    /** The generation files, oldest number first: [{file, number, compressed}]. */
    async _generations() {
        const pattern = new RegExp('^' + this.name + '\\.log\\.(\\d+)(\\.gz)?$');
        const entries = await fs.promises.readdir(this.directory);
        const generations = [];

        for (const entry of entries) {
            const match = pattern.exec(entry);
            if (match) {
                generations.push({
                    file: path.join(this.directory, entry),
                    number: Number(match[1]),
                    compressed: match[2] !== undefined,
                });
            }
        }

        return generations.sort((a, b) => a.number - b.number);
    }

    /** Run fn with no other rename of a generation in progress. */
    _with_fs_lock(fn) {
        const run = this.fs_lock.then(fn, fn);
        this.fs_lock = run.catch(() => {});
        return run;
    }

    async _rotate() {
        await this.handle.close();
        this.handle = null;

        await this._with_fs_lock(async () => {
            const generations = await this._generations();

            for (const generation of generations.reverse()) {
                const suffix = generation.compressed ? '.gz' : '';
                await fs.promises.rename(
                    generation.file,
                    path.join(this.directory, this.name + '.log.' + (generation.number + 1) + suffix)
                );
            }

            await fs.promises.rename(this.file, this.file + '.1');
        });

        this.sweep().catch((err) => this._report_failure(err));
    }

    /**
     * Compress and retire generations by age. Run after a rotation and on the daemon's slow
     * sweep, so a companion gone silent still has its old logs retired.
     */
    async sweep() {
        const retention_ms = this.logging.days_retention * DAY_MS;
        const plain_ms = this.logging.days_uncompressed * DAY_MS;
        const now = Date.now();
        const to_compress = [];

        await fs.promises.access(this.directory).catch(() => null);

        const generations = await this._generations().catch(() => []);

        for (const generation of generations) {
            const stat = await fs.promises.stat(generation.file).catch(() => null);
            if (stat === null) continue;

            const age = now - stat.mtimeMs;

            if (this.logging.days_retention > 0 && age > retention_ms) {
                await this._with_fs_lock(() => fs.promises.unlink(generation.file).catch(() => {}));
            } else if (!generation.compressed && age >= plain_ms) {
                to_compress.push({ file: generation.file, ino: stat.ino, mtime: stat.mtime });
            }
        }

        if (to_compress.length > 0 && !this.compressing) {
            this.compressing = true;
            try {
                for (const generation of to_compress) {
                    await this._compress(generation);
                }
            } finally {
                this.compressing = false;
            }
        }
    }

    /**
     * A rotation can renumber the file while it is being read, which is harmless: the open
     * descriptor still reads it. So the finished archive is matched back by inode, under the
     * rename lock, to learn the number it holds NOW.
     */
    async _compress(generation) {
        const scratch = path.join(this.directory, '.compress-' + process.pid + '-' + generation.ino);

        const source = fs.createReadStream(generation.file);
        await pipeline(source, zlib.createGzip(), fs.createWriteStream(scratch, { mode: 0o640 }));
        this._chown(scratch);

        await this._with_fs_lock(async () => {
            const generations = await this._generations();

            for (const candidate of generations) {
                if (candidate.compressed) continue;

                const stat = await fs.promises.stat(candidate.file).catch(() => null);
                if (stat !== null && stat.ino === generation.ino) {
                    await fs.promises.utimes(scratch, generation.mtime, generation.mtime);
                    await fs.promises.rename(scratch, candidate.file + '.gz');
                    await fs.promises.unlink(candidate.file);
                    return;
                }
            }

            await fs.promises.unlink(scratch);
        });
    }
}

module.exports = { Output_Log, MAX_QUEUE_LINES, MAX_QUEUE_BYTES };
