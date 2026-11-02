/**
 * The daemon's half of Redis DB 4. Companion_Store is the PHP half; the layout is stated in
 * WIRE_CONTRACT and each key is built here once.
 *
 * Redis keeps nothing: it has no persistence and evicts under pressure. So an instance record
 * has an EPOCH beside it, written at connect and checked every tick. A missing or moved epoch
 * means the data was lost, and the daemon republishes everything it knows, which is what makes
 * "instance not live" a retry for the SDK rather than a mass stop. History and the output stream
 * are evictable and are not republished: the log file is the authority.
 *
 * Every method rejects with no connection; the callers decide what that means.
 *
 * REALTIME. publish_events() is the one place the daemon tells browsers anything: it speaks the
 * realtime relay's own channel format, as PHP's Realtime::publish() does, so a frame reaches a
 * watcher with no PHP in between. Frames are notification-only - {name} and nothing else - and
 * the watcher refetches through the gated Rsx_Companion. A frame reaches only connections on the
 * site it is published to, so it is published once per site holding a matching subscription in
 * the relay's registry, and not at all when nobody watches (Task_Notify's rule, for the same
 * reason). The registry and the channels are not database-scoped; they live with the relay's
 * keys in database 0, which is why `realtime` is a second connection.
 */

const crypto = require('crypto');

/** The relay's keys (Realtime.php REDIS_PREFIX): the channel per site, and the registry set. */
const REALTIME_PREFIX = 'rsx_rt';
const REALTIME_REGISTRY_KEY = REALTIME_PREFIX + ':subs';

/** What each daemon event is, as a topic class (Core/Companion). */
const REALTIME_TOPICS = {
    changed: 'Companion_Changed_Topic',
    output: 'Companion_Output_Topic',
};

/**
 * The relay's matching rule (realtime-server.js matches_filter()): every key of the
 * subscription's filter equal, as a string, in the frame's data. An empty filter matches all.
 */
function filter_matches(filter, data) {
    for (const key of Object.keys(filter || {})) {
        if (String(data[key]) !== String(filter[key])) return false;
    }
    return true;
}

/**
 * The frames for a set of events: [[channel, message], ...], one per (topic, name, site) that a
 * registry member asks for. Pure, so a test needs no Redis.
 *
 * @param {Array<{kind: string, name: string}>} events
 * @param {string[]} members The registry's members: canonical JSON {site_id, topic, filter}
 * @param {number} ts Unix seconds
 */
function realtime_frames(events, members, ts) {
    const subscriptions = [];
    for (const member of members) {
        let entry;
        try {
            entry = JSON.parse(member);
        } catch (err) {
            continue;
        }
        if (entry && typeof entry.topic === 'string') subscriptions.push(entry);
    }

    const frames = [];
    const seen = new Set();
    for (const event of events) {
        const topic = REALTIME_TOPICS[event.kind];
        if (topic === undefined) continue;
        const data = { name: event.name };

        for (const entry of subscriptions) {
            if (entry.topic !== topic || !filter_matches(entry.filter, data)) continue;
            const site_id = Number(entry.site_id) || 0;
            const key = topic + '|' + event.name + '|' + site_id;
            if (seen.has(key)) continue;
            seen.add(key);
            frames.push([REALTIME_PREFIX + ':' + site_id, JSON.stringify({ topic, data, site_id, ts })]);
        }
    }

    return frames;
}

class Store {
    /**
     * @param {Redis_Client} redis Database 4
     * @param {?Redis_Client} realtime Database 0, the relay's; null while realtime is disabled
     */
    constructor(redis, prefix, host, realtime = null) {
        this.redis = redis;
        this.prefix = prefix;
        this.host = host;
        this.realtime = realtime;
        this.epoch = null;
    }

    instance_key(instance_id) { return this.prefix + 'instance:' + instance_id; }
    live_key() { return this.prefix + 'live'; }
    hosts_key() { return this.prefix + 'hosts'; }
    summary_key(host) { return this.prefix + 'summary:' + host; }
    history_key(name) { return this.prefix + 'history:' + name; }
    output_key(name) { return this.prefix + 'output:' + name; }
    epoch_key() { return this.prefix + 'epoch'; }

    /**
     * Answers true when the epoch was lost since this daemon last read it: everything must be
     * republished. SET NX so daemons on two hosts sharing a Redis settle on one value.
     */
    async check_epoch() {
        const fresh = crypto.randomBytes(16).toString('hex');

        await this.redis.command('SET', this.epoch_key(), fresh, 'NX');
        const current = await this.redis.command('GET', this.epoch_key());

        const lost = current !== this.epoch;
        this.epoch = current;

        return lost;
    }

    /** All string values, as the wire contract promises PHP. */
    instance_write_commands(record) {
        const command = ['HSET', this.instance_key(record.instance_id)];
        for (const [field, value] of Object.entries(record)) {
            command.push(field, value === null || value === undefined ? '' : String(value));
        }
        return [command, ['SADD', this.live_key(), record.instance_id]];
    }

    async write_instance(record) {
        await this.redis.pipeline(this.instance_write_commands(record));
    }

    /** The instance has ended: the record leaves the live set for the bounded history. */
    async end_instance(record, keep) {
        await this.redis.pipeline([
            ['DEL', this.instance_key(record.instance_id)],
            ['SREM', this.live_key(), record.instance_id],
            ['LPUSH', this.history_key(record.name), JSON.stringify(record)],
            ['LTRIM', this.history_key(record.name), 0, Math.max(keep, 1) - 1],
        ]);
    }

    /**
     * What a daemon does before it starts anything: its host's records are of a previous
     * daemon, whose children the sweep has already dealt with.
     */
    async clear_host(keep_for) {
        const ids = await this.redis.command('SMEMBERS', this.live_key());
        let cleared = 0;

        for (const instance_id of ids) {
            const pairs = await this.redis.command('HGETALL', this.instance_key(instance_id));
            const record = {};
            for (let i = 0; i < pairs.length; i += 2) {
                record[pairs[i]] = pairs[i + 1];
            }

            if (pairs.length === 0) {
                await this.redis.command('SREM', this.live_key(), instance_id);
            } else if (record.host === this.host) {
                record.status = 'killed';
                record.how_ended = 'daemon_lost';
                record.ended_at = String(Date.now());
                await this.end_instance(record, keep_for(record.name));
                cleared++;
            }
        }

        await this.redis.command('DEL', this.summary_key(this.host));

        return cleared;
    }

    async write_summaries(entries) {
        const command = ['HSET', this.summary_key(this.host)];
        for (const [field, value] of Object.entries(entries)) {
            command.push(field, JSON.stringify(value));
        }
        await this.redis.pipeline([command, ['SADD', this.hosts_key(), this.host]]);
    }

    async delete_summary(name) {
        await this.redis.command('HDEL', this.summary_key(this.host), name);
    }

    /** Capped near max_lines: "~" lets Redis trim on node boundaries, which is much cheaper. */
    async append_output(name, entries, max_lines) {
        await this.redis.pipeline(entries.map((entry) => [
            'XADD', this.output_key(name), 'MAXLEN', '~', max_lines, '*',
            'stream', entry.stream, 'line', entry.line, 'instance', entry.instance_id, 'at', entry.at,
        ]));
    }

    /**
     * Tell the realtime relay's watchers: events [{kind: 'changed' | 'output', name}]. One
     * registry read for the whole batch. Nothing depends on delivery: a watcher resyncs on every
     * (re)subscribe, so a lost frame costs a stale view until the next one.
     */
    async publish_events(events) {
        if (this.realtime === null || !this.realtime.connected || events.length === 0) return;

        const members = await this.realtime.command('SMEMBERS', REALTIME_REGISTRY_KEY);
        const frames = realtime_frames(events, members || [], Math.floor(Date.now() / 1000));

        if (frames.length > 0) {
            await this.realtime.pipeline(frames.map(([channel, message]) => ['PUBLISH', channel, message]));
        }
    }
}

module.exports = { Store, realtime_frames, REALTIME_TOPICS };
