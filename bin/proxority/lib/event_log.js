/**
 * The proxy's own record of what it did: the latest EVENT_LIMIT events in memory, and the
 * followers (`proxority.js log --follow`) that receive each new one as it is logged.
 *
 * It is not an access log - the public nginx keeps the only one - and nothing is written to
 * disk: the ring answers "what has the proxy been doing just now", which is what an operator
 * asks while a site is slow. Events that are not per-request traffic are also echoed to
 * stdout, where supervisor keeps them.
 */

/** How many events the ring keeps. A memory bound: older events drop off the end. */
const EVENT_LIMIT = 1000;

/** Per-request traffic: kept in the ring, never echoed (stdout would become an access log). */
const QUIET_TYPES = new Set(['queued', 'dispatched', 'released']);

class Event_Log {
    /**
     * @param {object} [options]
     * @param {number} [options.limit]
     * @param {?function(string): void} [options.echo]  receives each non-traffic line; null = silent
     */
    constructor(options = {}) {
        this.limit = options.limit || EVENT_LIMIT;
        this.echo = options.echo === undefined ? null : options.echo;
        this.events = [];
        this.start = 0;
        this.followers = new Set();
    }

    log(type, text) {
        const event = { ts: new Date().toISOString(), type, text };

        if (this.events.length < this.limit) {
            this.events.push(event);
        } else {
            this.events[this.start] = event;
            this.start = (this.start + 1) % this.limit;
        }

        if (this.echo && !QUIET_TYPES.has(type)) {
            this.echo(format_event(event));
        }

        for (const follower of this.followers) {
            follower(event);
        }

        return event;
    }

    /** The ring, oldest first. */
    entries() {
        return this.events.slice(this.start).concat(this.events.slice(0, this.start));
    }

    /** Receive every event logged from now on. Answers the unsubscribe function. */
    follow(callback) {
        this.followers.add(callback);
        return () => this.followers.delete(callback);
    }
}

function format_event(event) {
    return '[' + event.ts + '] [' + event.type + '] ' + event.text;
}

module.exports = { Event_Log, EVENT_LIMIT, format_event };
