/**
 * Rsx_Companion - watch a companion application and act on one, from the browser.
 *
 * Every call goes through Rsx_Companion_Controller, which asks the application's companion
 * gates (Companion_Gates: deny by default; a developer passes) - so a companion the viewer may
 * not see is "not found", and a verb they may not perform is refused.
 *
 *     const all = await Rsx_Companion.all();                 // [status, ...]
 *     const status = await Rsx_Companion.get('hello');       // {status, desired, can, ...}
 *     const {lines, last_id, trimmed} = await Rsx_Companion.output_after('hello', null);
 *     await Rsx_Companion.pause('hello');
 *
 * A verb returns once rsx-companiond on the server's box has it, and fails with a plain reason
 * when no daemon is running there. Desired state (start or stop for good) is the command line's.
 *
 * REALTIME. rsx-companiond publishes "something changed" frames and nothing else, filtered by
 * the companion's name; refetch on each one. Inside a component:
 *
 *     const refresh_soon = debounce(() => this.refresh(), Rsx_Companion.LIVE_UPDATE_DELAY);
 *     this.subscribe('Companion_Changed_Topic', { name: 'hello' }, () => refresh_soon());
 *
 * 'Companion_Output_Topic' {name} is the output feed (<Companion_Output> subscribes to it).
 * Every watch fires once on (re)subscribe as a resync, so write each callback as an idempotent
 * refetch.
 */
class Rsx_Companion {
    /** The delay every companion view debounces its realtime refetch by (Rsx_Task's). */
    static LIVE_UPDATE_DELAY = 250;

    /** The operator verbs. */
    static ACTIONS = ['pause', 'resume', 'stop', 'start', 'restart'];

    /** Every companion the viewer may see. */
    static async all() {
        return (await Rsx_Companion_Controller.all()).companions;
    }

    /** One companion's status (with `can`: the verbs the viewer may perform). */
    static async get(name) {
        return Rsx_Companion_Controller.get({ name: str(name) });
    }

    /** Live instances and the recent ended ones: {live: [...], history: [...]}. */
    static async instances(name, count = null) {
        const params = { name: str(name) };
        if (count) {
            params.count = int(count);
        }

        return Rsx_Companion_Controller.instances(params);
    }

    /**
     * Output after an opaque cursor: {lines: [{id, stream, line, instance, at}], last_id, more,
     * trimmed, is_live}. The cursor is a string the server answered; never parse it.
     *
     * @param {string} name
     * @param {string|null} after_id null reads from the oldest line the live stream holds
     * @param {string[]} [streams] any of stdout, stderr, daemon (default: all)
     */
    static async output_after(name, after_id, streams = null) {
        const params = { name: str(name), after_id: after_id };
        if (streams) {
            params.streams = streams;
        }

        return Rsx_Companion_Controller.output(params);
    }

    /** Endpoint traffic over the last hour: {endpoints: {name: {calls, errors, avg_ms}}, minutes}. */
    static async stats(name) {
        return Rsx_Companion_Controller.stats({ name: str(name) });
    }

    /** Pause a companion that uses the SDK; the process keeps running. */
    static async pause(name) {
        return Rsx_Companion.act(name, 'pause');
    }

    /** Resume a paused companion. */
    static async resume(name) {
        return Rsx_Companion.act(name, 'resume');
    }

    /** A graceful stop, held until start or restart; desired state untouched. */
    static async stop(name) {
        return Rsx_Companion.act(name, 'stop');
    }

    /** Release a hold: it starts if it is desired. */
    static async start(name) {
        return Rsx_Companion.act(name, 'start');
    }

    /** A graceful stop and an immediate start. */
    static async restart(name) {
        return Rsx_Companion.act(name, 'restart');
    }

    /** One verb; answers the companion's status as the request left it. */
    static async act(name, action) {
        return Rsx_Companion_Controller.act({ name: str(name), companion_action: str(action) });
    }
}
